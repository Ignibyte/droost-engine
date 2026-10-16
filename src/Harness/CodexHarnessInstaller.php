<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Installs Droost into Codex via a fenced TOML region.
 *
 * Writes a `[mcp_servers.droost]` table to `.codex/config.toml`. Guidance is
 * shared via AGENTS.md (Codex reads it natively), so no separate pointer.
 * With no server (`--no-mcp`), install removes the region, as uninstall does.
 */
final class CodexHarnessInstaller extends AbstractHarnessInstaller {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'codex';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Codex';
  }

  /**
   * {@inheritdoc}
   */
  public function isDetected(string $root): bool {
    return is_dir($root . '/.codex') || is_file($root . '/.codex/config.toml');
  }

  /**
   * {@inheritdoc}
   */
  public function install(string $root, InstallContext $context, InstallResult $result): void {
    if ($context->server === NULL) {
      $this->removeRegion($root, $result);
      return;
    }
    $existing = $this->read($root, '.codex/config.toml');
    // Respect a hand-added droost server outside our managed region rather
    // than defining it twice, which is invalid TOML: Codex would then read
    // none of the file.
    if (self::definesDroostOutsideTheRegion($existing)) {
      $result->addWarning('.codex/config.toml already defines mcp_servers.droost outside the Droost region; left unchanged.');
      return;
    }
    $body = Toml::mcpServerTable('droost', $context->server['command'], $context->server['args']);
    try {
      $new = ManagedRegion::upsert($existing, Markers::TOML_BEGIN, Markers::TOML_END, $body);
    }
    catch (\RuntimeException) {
      $result->addWarning('.codex/config.toml has a malformed Droost region (mismatched markers); left unchanged.');
      return;
    }
    $this->write($root, '.codex/config.toml', $new);
    $result->addWritten('.codex/config.toml');
    $this->warnOnTrailingKeys($new, $result);
  }

  /**
   * Whether the file defines the droost server anywhere but droost's region.
   *
   * Every spelling TOML allows: the table header (`[mcp_servers.droost]`,
   * quoted or spaced, or one of its sub-tables), a dotted key
   * (`mcp_servers.droost.command = …`), and a key under `[mcp_servers]`
   * (`droost = { … }`, `droost.command = …`). Comments are not definitions.
   *
   * @param string $toml
   *   The file's content.
   *
   * @return bool
   *   TRUE when a definition outside the region was found.
   */
  private static function definesDroostOutsideTheRegion(string $toml): bool {
    $begin = strpos($toml, Markers::TOML_BEGIN);
    $end = strpos($toml, Markers::TOML_END);
    if ($begin !== FALSE && $end !== FALSE && $end > $begin) {
      $toml = substr($toml, 0, $begin) . substr($toml, $end + strlen(Markers::TOML_END));
    }
    $droost = '(?:droost|"droost"|\'droost\')';
    $table = '';
    foreach (preg_split('/\R/', $toml) ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      if (preg_match('/^\[\[?\s*([^\]]+?)\s*\]\]?/', $line, $header) === 1) {
        $table = (string) preg_replace('/\s*\.\s*/', '.', $header[1]);
        if (preg_match('/^mcp_servers\.' . $droost . '(?:\.|$)/', $table) === 1) {
          return TRUE;
        }
        continue;
      }
      if ($table === '' && preg_match('/^mcp_servers\s*\.\s*' . $droost . '\s*[.=]/', $line) === 1) {
        return TRUE;
      }
      if ($table === 'mcp_servers' && preg_match('/^' . $droost . '\s*[.=]/', $line) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Warns if non-comment content follows the region (TOML would capture it).
   *
   * In TOML a `[table]` header owns every following key until the next header,
   * so bare keys placed after the Droost region would be attached to
   * `[mcp_servers.droost]`. Comments and further `[table]` headers are safe.
   *
   * @param string $toml
   *   The written TOML.
   * @param \Droost\Engine\Harness\InstallResult $result
   *   The result accumulator.
   */
  private function warnOnTrailingKeys(string $toml, InstallResult $result): void {
    $end = strpos($toml, Markers::TOML_END);
    if ($end === FALSE) {
      return;
    }
    foreach (preg_split('/\R/', substr($toml, $end + strlen(Markers::TOML_END))) ?: [] as $line) {
      $trimmed = trim($line);
      if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '[')) {
        continue;
      }
      $result->addWarning('.codex/config.toml has key/value content after the Droost region with no [table] header of its own; TOML would attach it to [mcp_servers.droost]. Move it above the region or under its own table.');
      return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(string $root, InstallResult $result): void {
    $this->removeRegion($root, $result);
  }

  /**
   * Removes droost's fenced region from `.codex/config.toml`, if present.
   *
   * @param string $root
   *   The project root.
   * @param \Droost\Engine\Harness\InstallResult $result
   *   The result accumulator.
   */
  private function removeRegion(string $root, InstallResult $result): void {
    $existing = $this->read($root, '.codex/config.toml');
    if (!ManagedRegion::isWellFormed($existing, Markers::TOML_BEGIN, Markers::TOML_END)) {
      return;
    }
    if (ManagedRegion::isOnlyRegion($existing, Markers::TOML_BEGIN, Markers::TOML_END)) {
      $this->delete($root, '.codex/config.toml');
      $this->removeDirIfEmpty($root, '.codex');
      $result->addRemoved('.codex/config.toml (file)');
      return;
    }
    $this->write($root, '.codex/config.toml', ManagedRegion::strip($existing, Markers::TOML_BEGIN, Markers::TOML_END));
    $result->addRemoved('.codex/config.toml (region)');
  }

}
