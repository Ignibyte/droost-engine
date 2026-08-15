<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Immutable inputs shared by every harness installer for one install run.
 */
final readonly class InstallContext {

  /**
   * Constructs an InstallContext.
   *
   * @param string $command
   *   The MCP server launch command (e.g. "ddev").
   * @param array<int, string> $args
   *   The launch command arguments (e.g. ["drush", "mcp:server"]).
   * @param string $guidelinesMode
   *   How to write guidance: "block" (full text — AGENTS.md only), "pointer"
   *   (reference AGENTS.md), or "none" (MCP entry only).
   * @param string $blockBody
   *   The full guidelines block body (without markers), for the AGENTS writer.
   *   Pointer writers build their own (import vs sentence) pointer.
   */
  public function __construct(
    public string $command,
    public array $args,
    public string $guidelinesMode,
    public string $blockBody,
  ) {}

  /**
   * Whether guideline regions should be written at all.
   *
   * @return bool
   *   TRUE unless the mode is "none".
   */
  public function writesGuidelines(): bool {
    return $this->guidelinesMode !== 'none';
  }

}
