<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Codex: removes the MCP region an earlier droost wrote.
 *
 * Guidance is shared via AGENTS.md, which Codex reads natively, so there is
 * no pointer to write. droost ships no MCP server (2026-10-19), so install
 * removes the fenced `[mcp_servers.droost]` region from `.codex/config.toml`,
 * as uninstall does.
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
    $this->removeRegion($root, $result);
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
