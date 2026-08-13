<?php

declare(strict_types=1);

namespace Droost\Engine\Verify;

/**
 * The result of one verify check (phpcs / phpstan / phpunit).
 *
 * A leg is DATA, not a tool outcome: a failing check is a successful verify
 * (the agent asked "is it green?" and got an answer). `passed` is the check's
 * verdict; the tool that runs the legs succeeds regardless.
 */
final readonly class LegResult {

  /**
   * Constructs a LegResult.
   *
   * @param string $check
   *   The check id (`phpcs`, `phpstan`, `phpunit`).
   * @param bool $passed
   *   Whether the check found no problems (exit 0).
   * @param int $exitCode
   *   The check process exit code.
   * @param int $durationMs
   *   Wall-clock duration of the check, in milliseconds.
   * @param string $summary
   *   A one-line human summary (counts, or the reason a leg could not run).
   * @param array<int, array<string, mixed>> $findings
   *   Per-message findings: `{file, line, message, source, severity}`.
   * @param bool $findingsTruncated
   *   TRUE when more findings existed than the cap and `findings` was trimmed.
   */
  public function __construct(
    public string $check,
    public bool $passed,
    public int $exitCode,
    public int $durationMs,
    public string $summary,
    public array $findings,
    public bool $findingsTruncated,
  ) {}

  /**
   * The check as a plain array for the tool's data envelope.
   *
   * @return array<string, mixed>
   *   The serialized leg.
   */
  public function toArray(): array {
    return [
      'check' => $this->check,
      'passed' => $this->passed,
      'exit_code' => $this->exitCode,
      'duration_ms' => $this->durationMs,
      'summary' => $this->summary,
      'findings' => $this->findings,
      'findings_truncated' => $this->findingsTruncated,
    ];
  }

}
