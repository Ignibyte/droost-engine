<?php

declare(strict_types=1);

namespace Droost\Engine\Verify;

use Droost\Engine\Support\ProjectRoot;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the QA loop (phpcs / phpstan / phpunit / deprecations) against a path.
 *
 * The engine's EXTERNAL subprocess seam: phpcs and phpstan are project
 * binaries, not in-process console apps, so they are spawned via Symfony
 * Process, which this package requires outright rather than borrowing from a
 * host runtime. Argument-building and output-parsing are PURE static functions
 * (unit-tested against canned reports) and the spawn between them is kept
 * thin, so everything worth asserting can be asserted without running a
 * binary. A failing check is a LEG result ([`LegResult`]), never an exception
 * — the loop always returns a verdict.
 */
final class VerifyRunner {

  /**
   * The checks this runner supports, in inventory order.
   */
  public const CHECKS = ['phpcs', 'phpstan', 'phpunit', 'deprecations'];

  /**
   * Checks whose binary name differs from the check id.
   */
  private const BINARIES = ['deprecations' => 'phpstan'];

  /**
   * The default phpcs coding standard when the caller supplies none.
   */
  public const DEFAULT_STANDARD = 'Drupal,DrupalPractice';

  /**
   * The per-leg findings cap — MCP payloads stay bounded on legacy codebases.
   */
  public const FINDINGS_CAP = 200;

  /**
   * How long phpcs / phpstan may run before they are killed (seconds).
   */
  private const DEFAULT_TIMEOUT = 120.0;

  /**
   * How long phpunit may run before it is killed (seconds).
   */
  private const PHPUNIT_TIMEOUT = 300.0;

  /**
   * Constructs a VerifyRunner.
   *
   * @param string $appRoot
   *   The Drupal application root (locates the core phpunit config).
   * @param \Droost\Engine\Support\ProjectRoot $projectRoot
   *   The project root (locates `vendor/bin` and is the process cwd).
   */
  public function __construct(
    private readonly string $appRoot,
    private readonly ProjectRoot $projectRoot,
  ) {}

  /**
   * Reports which checks are runnable, WITHOUT spawning anything.
   *
   * @return array<string, array<string, mixed>>
   *   Per check: `{binary: string|null, config: string}` — the binary path if
   *   found, and the config strategy that would be used.
   */
  public function inventory(): array {
    $out = [];
    foreach (self::CHECKS as $check) {
      $bin = $this->binPath($check);
      $out[$check] = [
        'binary' => is_file($bin) ? $bin : NULL,
        'config' => $this->configNote($check),
      ];
    }
    return $out;
  }

  /**
   * Runs each requested check against `$target`.
   *
   * @param string $target
   *   The absolute path to check (a module directory or a file).
   * @param array<int, string> $checks
   *   The checks to run (a subset of [`CHECKS`]).
   * @param string|null $standard
   *   An override phpcs standard, or NULL for [`DEFAULT_STANDARD`].
   *
   * @return array<int, \Droost\Engine\Verify\LegResult>
   *   One result per check, in the requested order.
   */
  public function run(string $target, array $checks, ?string $standard): array {
    $legs = [];
    foreach ($checks as $check) {
      $legs[] = $this->runOne($check, $target, $standard);
    }
    return $legs;
  }

  /**
   * Runs one check and captures a structured result.
   *
   * @param string $check
   *   The check id.
   * @param string $target
   *   The absolute path to check.
   * @param string|null $standard
   *   The phpcs standard override.
   *
   * @return \Droost\Engine\Verify\LegResult
   *   The leg result.
   */
  private function runOne(string $check, string $target, ?string $standard): LegResult {
    $bin = $this->binPath($check);
    if (!is_file($bin)) {
      return new LegResult($check, FALSE, -1, 0, sprintf('%s binary not found at %s', $check, $bin), [], FALSE);
    }
    if ($check === 'deprecations' && !$this->phpstanDrupalInstalled()) {
      return new LegResult($check, FALSE, -1, 0, 'the mglaman/phpstan-drupal extension is not installed in this project (composer require --dev mglaman/phpstan-drupal)', [], FALSE);
    }
    $argv = match ($check) {
      'phpcs' => self::buildPhpcsArgv($bin, $target, $standard ?? self::DEFAULT_STANDARD),
      'phpstan' => self::buildPhpstanArgv($bin, $target, $this->phpstanConfig($target)),
      'phpunit' => self::buildPhpunitArgv($bin, $target, $this->phpunitConfig()),
      'deprecations' => self::buildDeprecationsArgv($bin, $target),
      default => NULL,
    };
    if ($argv === NULL) {
      return new LegResult($check, FALSE, -1, 0, sprintf('unknown check "%s"', $check), [], FALSE);
    }

    $timeout = $check === 'phpunit' ? self::PHPUNIT_TIMEOUT : self::DEFAULT_TIMEOUT;
    $start = microtime(TRUE);
    $process = new Process($argv, $this->projectRoot->path(), NULL, NULL, $timeout);
    try {
      $process->run();
    }
    catch (ProcessTimedOutException) {
      return new LegResult($check, FALSE, -1, self::elapsedMs($start), sprintf('%s timed out after %ds', $check, (int) $timeout), [], FALSE);
    }
    $ms = self::elapsedMs($start);
    $exit = $process->getExitCode() ?? -1;
    $parsed = match ($check) {
      'phpcs' => self::parsePhpcsJson($process->getOutput(), $exit),
      'phpstan' => self::parsePhpstanJson($process->getOutput(), $exit),
      'phpunit' => self::parsePhpunitOutput($process->getOutput(), $process->getErrorOutput(), $exit),
      'deprecations' => self::parseDeprecationsJson($process->getOutput(), $exit),
      default => ['passed' => FALSE, 'summary' => 'unhandled check', 'findings' => []],
    };

    $findings = $parsed['findings'];
    $truncated = count($findings) > self::FINDINGS_CAP;
    if ($truncated) {
      $findings = array_slice($findings, 0, self::FINDINGS_CAP);
    }
    return new LegResult($check, (bool) $parsed['passed'], $exit, $ms, (string) $parsed['summary'], $findings, $truncated);
  }

  /**
   * Builds the phpcs argv (a JSON report against `$target`).
   *
   * @param string $bin
   *   The phpcs binary path.
   * @param string $target
   *   The path to check.
   * @param string $standard
   *   The coding standard.
   *
   * @return list<string>
   *   The argv.
   */
  public static function buildPhpcsArgv(string $bin, string $target, string $standard): array {
    return [$bin, '-q', '--report=json', '--standard=' . $standard, $target];
  }

  /**
   * Builds the phpstan argv (a JSON report against `$target`).
   *
   * @param string $bin
   *   The phpstan binary path.
   * @param string $target
   *   The path to analyse.
   * @param string|null $configFile
   *   A module-local `phpstan.neon` when present, else NULL (uses --level=max).
   *
   * @return list<string>
   *   The argv.
   */
  public static function buildPhpstanArgv(string $bin, string $target, ?string $configFile): array {
    $argv = [$bin, 'analyse', '--no-progress', '--error-format=json'];
    if ($configFile !== NULL) {
      $argv[] = '-c';
      $argv[] = $configFile;
    }
    else {
      $argv[] = '--level=max';
    }
    $argv[] = $target;
    return $argv;
  }

  /**
   * Builds the phpunit argv against `$target`.
   *
   * @param string $bin
   *   The phpunit binary path.
   * @param string $target
   *   The path to test.
   * @param string|null $configFile
   *   A discoverable phpunit config (`web/core/phpunit.xml.dist`), or NULL.
   *
   * @return list<string>
   *   The argv.
   */
  public static function buildPhpunitArgv(string $bin, string $target, ?string $configFile): array {
    $argv = [$bin];
    if ($configFile !== NULL) {
      $argv[] = '-c';
      $argv[] = $configFile;
    }
    $argv[] = $target;
    return $argv;
  }

  /**
   * Builds the deprecations argv (phpstan at level 0, JSON report).
   *
   * The deprecation findings come from the project's installed
   * mglaman/phpstan-drupal + phpstan-deprecation-rules extensions
   * (auto-registered by phpstan/extension-installer); level 0 keeps the
   * general-analysis noise floor minimal while the custom deprecation rules
   * (which are not level-gated) still fire — the drupal-check shape. The
   * module's own `phpstan.neon` (typically level max) is never used — it
   * must not bleed general findings into this leg — but the leg does pass
   * its OWN packaged config, because phpstan's default
   * reportUnmatchedIgnoredErrors would otherwise turn the module's
   * legitimate level-max inline ignores into false deprecation failures:
   * at level 0 those ignores match nothing by construction.
   *
   * @param string $bin
   *   The phpstan binary path.
   * @param string $target
   *   The path to analyse.
   *
   * @return list<string>
   *   The argv.
   */
  public static function buildDeprecationsArgv(string $bin, string $target): array {
    return [
      $bin,
      'analyse',
      '--no-progress',
      '--error-format=json',
      '--level=0',
      '-c',
      __DIR__ . '/deprecations.neon',
      $target,
    ];
  }

  /**
   * Parses a phpcs `--report=json` document.
   *
   * @param string $stdout
   *   The phpcs stdout.
   * @param int $exitCode
   *   The phpcs exit code (the fallback verdict when the JSON is unparseable).
   *
   * @return array{passed: bool, summary: string, findings: array<int, array<string, mixed>>}
   *   The parsed leg pieces.
   */
  public static function parsePhpcsJson(string $stdout, int $exitCode): array {
    $data = json_decode($stdout, TRUE);
    if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
      return ['passed' => $exitCode === 0, 'summary' => 'phpcs produced no parseable JSON report', 'findings' => []];
    }
    $findings = [];
    foreach ($data['files'] as $file => $info) {
      $messages = is_array($info) && isset($info['messages']) && is_array($info['messages']) ? $info['messages'] : [];
      foreach ($messages as $message) {
        if (!is_array($message)) {
          continue;
        }
        $findings[] = [
          'file' => (string) $file,
          'line' => self::toInt($message['line'] ?? 0),
          'message' => self::toStr($message['message'] ?? ''),
          'source' => self::toStr($message['source'] ?? ''),
          'severity' => strtolower(self::toStr($message['type'] ?? '')),
        ];
      }
    }
    $totals = is_array($data['totals'] ?? NULL) ? $data['totals'] : [];
    $errors = self::toInt($totals['errors'] ?? 0);
    $warnings = self::toInt($totals['warnings'] ?? 0);
    return [
      'passed' => $errors === 0 && $warnings === 0,
      'summary' => sprintf('%d error(s), %d warning(s)', $errors, $warnings),
      'findings' => $findings,
    ];
  }

  /**
   * Parses a phpstan `--error-format=json` document.
   *
   * @param string $stdout
   *   The phpstan stdout.
   * @param int $exitCode
   *   The phpstan exit code (the fallback verdict when JSON does not parse).
   *
   * @return array{passed: bool, summary: string, findings: array<int, array<string, mixed>>}
   *   The parsed leg pieces.
   */
  public static function parsePhpstanJson(string $stdout, int $exitCode): array {
    $data = json_decode($stdout, TRUE);
    if (!is_array($data)) {
      return ['passed' => $exitCode === 0, 'summary' => 'phpstan produced no parseable JSON report', 'findings' => []];
    }
    $findings = [];
    $files = is_array($data['files'] ?? NULL) ? $data['files'] : [];
    foreach ($files as $file => $info) {
      $messages = is_array($info) && isset($info['messages']) && is_array($info['messages']) ? $info['messages'] : [];
      foreach ($messages as $message) {
        if (!is_array($message)) {
          continue;
        }
        $findings[] = [
          'file' => (string) $file,
          'line' => self::toInt($message['line'] ?? 0),
          'message' => self::toStr($message['message'] ?? ''),
          'source' => 'phpstan',
          'severity' => 'error',
        ];
      }
    }
    // General (not-file-tied) errors, e.g. a broken config.
    foreach (is_array($data['errors'] ?? NULL) ? $data['errors'] : [] as $general) {
      $findings[] = [
        'file' => '(general)',
        'line' => 0,
        'message' => is_string($general) ? $general : '',
        'source' => 'phpstan',
        'severity' => 'error',
      ];
    }
    $totals = is_array($data['totals'] ?? NULL) ? $data['totals'] : [];
    $count = self::toInt($totals['file_errors'] ?? 0) + self::toInt($totals['errors'] ?? 0);
    return [
      'passed' => $count === 0,
      'summary' => sprintf('%d error(s)', $count),
      'findings' => $findings,
    ];
  }

  /**
   * Parses the deprecations leg's phpstan JSON, tagging the findings.
   *
   * The document is ordinary phpstan `--error-format=json` (the leg IS
   * phpstan); the walk is delegated and each finding's source is set to
   * "deprecation" — leg-uniform tagging, the key the version-delta ETL
   * will read.
   *
   * @param string $stdout
   *   The phpstan stdout.
   * @param int $exitCode
   *   The phpstan exit code (the fallback verdict when JSON does not parse).
   *
   * @return array{passed: bool, summary: string, findings: array<int, array<string, mixed>>}
   *   The parsed leg pieces.
   */
  public static function parseDeprecationsJson(string $stdout, int $exitCode): array {
    $parsed = self::parsePhpstanJson($stdout, $exitCode);
    $parsed['findings'] = array_map(
      static fn (array $finding): array => array_merge($finding, ['source' => 'deprecation']),
      $parsed['findings'],
    );
    return $parsed;
  }

  /**
   * Extracts a phpunit verdict + summary line (v1: no per-test findings).
   *
   * @param string $stdout
   *   The phpunit stdout.
   * @param string $stderr
   *   The phpunit stderr (a bootstrap failure lands here).
   * @param int $exitCode
   *   The phpunit exit code.
   *
   * @return array{passed: bool, summary: string, findings: array<int, array<string, mixed>>}
   *   The parsed leg pieces.
   */
  public static function parsePhpunitOutput(string $stdout, string $stderr, int $exitCode): array {
    $summary = sprintf('phpunit exited %d', $exitCode);
    foreach (preg_split('/\R/', $stdout . "\n" . $stderr) ?: [] as $line) {
      $trimmed = trim($line);
      if ($trimmed !== '' && preg_match('/^(OK|OK, but|FAILURES!|ERRORS!|WARNINGS!|Tests:|No tests executed|Cannot open|PHP Fatal|Error:)/', $trimmed) === 1) {
        $summary = $trimmed;
        break;
      }
    }
    return ['passed' => $exitCode === 0, 'summary' => $summary, 'findings' => []];
  }

  /**
   * The absolute path of a check's binary under the project's `vendor/bin`.
   *
   * @param string $check
   *   The check id.
   *
   * @return string
   *   The binary path (not guaranteed to exist).
   */
  private function binPath(string $check): string {
    return $this->projectRoot->path() . '/vendor/bin/' . (self::BINARIES[$check] ?? $check);
  }

  /**
   * Whether the mglaman/phpstan-drupal extension is installed in the project.
   *
   * The deprecations leg relies on phpstan-drupal (and its hard dependency
   * phpstan-deprecation-rules) being auto-registered by
   * phpstan/extension-installer. Without the package the run would silently
   * report nothing, so absence is surfaced as a structured leg row instead
   * of a green lie.
   *
   * @return bool
   *   TRUE when the package directory exists under the project's vendor.
   */
  private function phpstanDrupalInstalled(): bool {
    return is_dir($this->projectRoot->path() . '/vendor/mglaman/phpstan-drupal');
  }

  /**
   * The module-local phpstan config for `$target`, if present.
   *
   * @param string $target
   *   The path being analysed.
   *
   * @return string|null
   *   The `phpstan.neon` path, or NULL to fall back to --level=max.
   */
  private function phpstanConfig(string $target): ?string {
    foreach (['/phpstan.neon', '/phpstan.neon.dist'] as $name) {
      if (is_file($target . $name)) {
        return $target . $name;
      }
    }
    return NULL;
  }

  /**
   * The discoverable phpunit config (Drupal core's dist), if present.
   *
   * @return string|null
   *   The `core/phpunit.xml.dist` path, or NULL.
   */
  private function phpunitConfig(): ?string {
    $core = $this->appRoot . '/core/phpunit.xml.dist';
    return is_file($core) ? $core : NULL;
  }

  /**
   * A human note describing a check's config strategy (for the inventory).
   *
   * @param string $check
   *   The check id.
   *
   * @return string
   *   The note.
   */
  private function configNote(string $check): string {
    return match ($check) {
      'phpcs' => 'standard ' . self::DEFAULT_STANDARD . ' (override with the "standard" argument)',
      'phpstan' => 'a module-local phpstan.neon when present, else --level=max',
      'phpunit' => $this->phpunitConfig() ?? 'no discoverable phpunit config (core/phpunit.xml.dist absent)',
      'deprecations' => 'phpstan --level=0 with the installed phpstan-drupal deprecation rules (phpstan-drupal: '
        . ($this->phpstanDrupalInstalled() ? 'installed' : 'MISSING — composer require --dev mglaman/phpstan-drupal')
        . ')',
      default => 'unknown',
    };
  }

  /**
   * Narrows a JSON-decoded value to an int (non-numeric becomes 0).
   *
   * @param mixed $value
   *   The decoded value.
   *
   * @return int
   *   The int.
   */
  private static function toInt(mixed $value): int {
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Narrows a JSON-decoded value to a string (non-scalar becomes '').
   *
   * @param mixed $value
   *   The decoded value.
   *
   * @return string
   *   The string.
   */
  private static function toStr(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Milliseconds elapsed since `$start` (a `microtime(TRUE)` reading).
   *
   * @param float $start
   *   The start reading.
   *
   * @return int
   *   The elapsed milliseconds.
   */
  private static function elapsedMs(float $start): int {
    return (int) round((microtime(TRUE) - $start) * 1000);
  }

}
