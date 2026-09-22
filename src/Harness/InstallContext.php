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
   * There is no longer a mode that skips the instruction files. It existed so
   * a site could decline droost's guidelines; the guidelines are gone, and
   * what the files now carry — the AGENTS.md block and each editor's pointer
   * to it — is also the only road by which the build pipeline's own AGENTS.md
   * block reaches Claude Code, Qwen, Gemini and opencode. A switch that could
   * silently cut that road is not an option worth keeping.
   *
   * @param string $command
   *   The MCP server launch command (e.g. "ddev").
   * @param array<int, string> $args
   *   The launch command arguments (e.g. ["drush", "mcp:server"]).
   * @param string $blockBody
   *   The AGENTS.md block body (without markers). The pointer writers build
   *   their own import or sentence pointer to it.
   */
  public function __construct(
    public string $command,
    public array $args,
    public string $blockBody,
  ) {}

}
