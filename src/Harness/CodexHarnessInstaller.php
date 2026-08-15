<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Installs Droost into Codex via a fenced TOML region.
 *
 * Writes a `[mcp_servers.droost]` table to `.codex/config.toml`. Guidance is
 * shared via AGENTS.md (Codex reads it natively), so no separate pointer.
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
    $existing = $this->read($root, '.codex/config.toml');
    // Respect a hand-added table outside our managed region rather than
    // duplicating it. Match the table header at line start (not in a comment).
    if (!ManagedRegion::hasAnyMarker($existing, Markers::TOML_BEGIN, Markers::TOML_END)
      && preg_match('/^\s*\[mcp_servers\.droost\]/m', $existing) === 1) {
      $result->addWarning('.codex/config.toml already defines [mcp_servers.droost] outside the Droost region; left unchanged.');
      return;
    }
    $body = Toml::mcpServerTable('droost', $context->command, $context->args);
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
