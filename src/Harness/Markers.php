<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Begin/end markers that delimit Droost's managed regions in shared files.
 *
 * Droost only ever owns the content between a marker pair; everything outside
 * is the user's and is preserved verbatim. Markdown files use HTML comments;
 * TOML uses hash comments.
 */
final class Markers {

  /**
   * Markdown managed-block markers (AGENTS.md, CLAUDE.md, QWEN.md, GEMINI.md).
   */
  public const string MD_BEGIN = '<!-- BEGIN DROOST GUIDELINES -->';
  public const string MD_END = '<!-- END DROOST GUIDELINES -->';

  /**
   * TOML managed-region markers (Codex config.toml).
   */
  public const string TOML_BEGIN = '# >>> BEGIN DROOST MCP >>>';
  public const string TOML_END = '# <<< END DROOST MCP <<<';

}
