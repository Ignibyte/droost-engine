<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Writes (and removes) droost's MCP server entry and guidance for one harness.
 *
 * Each implementation owns the config/instruction files of a single coding
 * harness (Claude Code, Codex, opencode, qwen-code, gemini-cli) and writes only
 * Droost-managed regions, leaving all user content intact.
 */
interface HarnessInstallerInterface {

  /**
   * The harness machine id (e.g. "claude", "codex").
   *
   * @return string
   *   The id.
   */
  public function getId(): string;

  /**
   * The human-readable harness label.
   *
   * @return string
   *   The label.
   */
  public function label(): string;

  /**
   * Whether this harness appears to be in use under the given project root.
   *
   * @param string $root
   *   The project root.
   *
   * @return bool
   *   TRUE if a signature file/dir is present.
   */
  public function isDetected(string $root): bool;

  /**
   * Installs this harness's guidance, and droost's MCP server entry.
   *
   * With no server in the context, a droost entry from an earlier install is
   * removed.
   *
   * @param string $root
   *   The project root.
   * @param \Droost\Engine\Harness\InstallContext $context
   *   The shared install inputs.
   * @param \Droost\Engine\Harness\InstallResult $result
   *   The result accumulator to record actions on.
   */
  public function install(string $root, InstallContext $context, InstallResult $result): void;

  /**
   * Removes Droost-managed regions/entries for this harness.
   *
   * @param string $root
   *   The project root.
   * @param \Droost\Engine\Harness\InstallResult $result
   *   The result accumulator to record removals on.
   */
  public function uninstall(string $root, InstallResult $result): void;

}
