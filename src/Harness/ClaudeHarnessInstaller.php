<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

use Droost\Engine\Skills\Skill;
use Droost\Engine\Skills\SkillMdWriter;
use Droost\Engine\Skills\SkillProvider;

/**
 * Claude Code: `.mcp.json` MCP server + a CLAUDE.md import of AGENTS.md.
 *
 * Also renders Droost's skills as native Claude Agent Skills under
 * `.claude/skills/<name>/SKILL.md`, so the guidance auto-triggers in context.
 */
final class ClaudeHarnessInstaller extends AbstractHarnessInstaller {

  /**
   * Sentinel file marking a skill directory as Droost-authored (ours).
   */
  private const string SENTINEL = '.droost-skill';

  /**
   * Constructs a ClaudeHarnessInstaller.
   *
   * @param \Droost\Engine\Skills\SkillProvider $skills
   *   The skill provider.
   * @param \Droost\Engine\Skills\SkillMdWriter $writer
   *   The shared SKILL.md renderer (the single format authority).
   */
  public function __construct(
    private readonly SkillProvider $skills,
    private readonly SkillMdWriter $writer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'claude';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Claude Code';
  }

  /**
   * {@inheritdoc}
   */
  public function isDetected(string $root): bool {
    return is_file($root . '/.mcp.json')
      || is_file($root . '/CLAUDE.md')
      || is_dir($root . '/.claude');
  }

  /**
   * {@inheritdoc}
   */
  public function install(string $root, InstallContext $context, InstallResult $result): void {
    $this->upsertJsonServer($root, '.mcp.json', 'mcpServers', $context, $result);
    if (!$context->writesGuidelines()) {
      return;
    }
    $this->upsertMarkdown($root, 'CLAUDE.md', $this->importPointer(), $result);
    foreach ($this->skills->getSkills() as $skill) {
      $dir = '.claude/skills/' . $skill->name;
      // Never overwrite a skill directory we did not author.
      if (is_dir($root . '/' . $dir) && !is_file($root . '/' . $dir . '/' . self::SENTINEL)) {
        $result->addWarning(sprintf('%s exists and is not Droost-managed; skipped.', $dir));
        continue;
      }
      $this->write($root, $dir . '/SKILL.md', $this->renderSkill($skill));
      $this->write($root, $dir . '/' . self::SENTINEL, "Droost-authored skill; safe to delete via `drush droost:uninstall`.\n");
      $result->addWritten($dir . '/SKILL.md');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(string $root, InstallResult $result): void {
    $this->removeJsonServer($root, '.mcp.json', 'mcpServers', $result);
    $this->removeMarkdown($root, 'CLAUDE.md', $result);
    // Remove only Droost-authored skill dirs (sentinel present), which also
    // cleans up orphans left by a renamed/removed skill or topic.
    foreach (glob($root . '/.claude/skills/*', GLOB_ONLYDIR) ?: [] as $absolute) {
      if (is_file($absolute . '/' . self::SENTINEL)) {
        $this->deleteDir($root, '.claude/skills/' . basename($absolute));
        $result->addRemoved('.claude/skills/' . basename($absolute) . ' (skill)');
      }
    }
  }

  /**
   * Renders a skill as a Claude Agent Skill SKILL.md.
   *
   * Delegates to the shared SkillMdWriter so the Claude install and the
   * standalone `droost:skills:emit` command render byte-identical frontmatter.
   *
   * @param \Droost\Engine\Skills\Skill $skill
   *   The skill.
   *
   * @return string
   *   The SKILL.md content.
   */
  private function renderSkill(Skill $skill): string {
    return $this->writer->render($skill);
  }

}
