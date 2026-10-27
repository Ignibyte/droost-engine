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
   *
   * "GUIDELINES" names a catalogue droost no longer ships, and the marker
   * keeps it on purpose: every existing site's block is found by this exact
   * text, so a rename would need a migration, and a half-done one leaves two
   * blocks in the file. What the block says is the body's business.
   */
  public const string MD_BEGIN = '<!-- BEGIN DROOST GUIDELINES -->';
  public const string MD_END = '<!-- END DROOST GUIDELINES -->';

  /**
   * TOML managed-region markers (Codex config.toml).
   *
   * No TOML region is written now; these find the one an earlier droost
   * wrote, so it can be removed.
   */
  public const string TOML_BEGIN = '# >>> BEGIN DROOST MCP >>>';
  public const string TOML_END = '# <<< END DROOST MCP <<<';

}
