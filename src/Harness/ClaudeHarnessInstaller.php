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
 * `.claude/skills/<name>/SKILL.md`, the slash commands under
 * `.claude/commands/`, and sweeps the skills droost no longer ships.
 *
 * The CLAUDE.md import is the only road by which Claude Code reads AGENTS.md
 * — and so the only road by which the build pipeline's own block in it
 * reaches the agent. It is written unconditionally for that reason.
 */
final class ClaudeHarnessInstaller extends AbstractHarnessInstaller {

  /**
   * Sentinel file marking a skill directory as Droost-authored (ours).
   */
  private const string SENTINEL = SkillMdWriter::SENTINEL;

  /**
   * The only files a skill directory holds when droost wrote all of it.
   */
  private const array SKILL_FILES = [SkillMdWriter::SENTINEL, 'SKILL.md'];

  /**
   * Constructs a ClaudeHarnessInstaller.
   *
   * @param \Droost\Engine\Skills\SkillProvider $skills
   *   The skill provider.
   * @param \Droost\Engine\Skills\SkillMdWriter $writer
   *   The shared SKILL.md renderer (the single format authority).
   * @param \Droost\Engine\Harness\CommandProvider|null $commands
   *   The slash-command provider, or NULL for a consumer shipping none.
   */
  public function __construct(
    private readonly SkillProvider $skills,
    private readonly SkillMdWriter $writer,
    private readonly ?CommandProvider $commands = NULL,
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
    $this->upsertMarkdown($root, 'CLAUDE.md', $this->importPointer(), $result);
    $emitted = [];
    foreach ($this->skills->getSkills() as $skill) {
      $emitted[$skill->name] = TRUE;
      $dir = '.claude/skills/' . $skill->name;
      // Never overwrite a skill directory we did not author.
      if (is_dir($root . '/' . $dir) && !is_file($root . '/' . $dir . '/' . self::SENTINEL)) {
        $result->addWarning(sprintf('%s exists and is not Droost-managed; skipped.', $dir));
        continue;
      }
      $content = $this->renderSkill($skill);
      $this->write($root, $dir . '/SKILL.md', $content);
      $this->write($root, $dir . '/' . self::SENTINEL, SkillMdWriter::sentinel($content));
      $result->addWritten($dir . '/SKILL.md');
    }
    $this->sweepRetired($root, $emitted, $result);
    // Commands are single files in a directory shared with the user's own:
    // refresh exactly the provided names, touch nothing else. The names are
    // droost-prefixed by convention, which is what keeps "refresh" from
    // ever meaning "clobber something a human wrote".
    foreach ($this->commands?->getCommands() ?? [] as $name => $content) {
      $relative = '.claude/commands/' . $name . '.md';
      $this->write($root, $relative, $content);
      $result->addWritten($relative);
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
    // Commands: remove exactly the names this provider ships, nothing else —
    // the same contract as install, mirrored. Nested names then prune the
    // subdirectories they implied, deepest first and only while empty, so a
    // user file dropped beside ours keeps its directory alive.
    $prune = [];
    foreach (array_keys($this->commands?->getCommands() ?? []) as $name) {
      $relative = '.claude/commands/' . $name . '.md';
      if (is_file($root . '/' . $relative)) {
        @unlink($root . '/' . $relative);
        $result->addRemoved($relative . ' (command)');
      }
      $dir = dirname('.claude/commands/' . $name);
      while ($dir !== '.claude/commands') {
        $prune[$dir] = TRUE;
        $dir = dirname($dir);
      }
    }
    $dirs = array_keys($prune);
    usort($dirs, static fn (string $a, string $b): int =>
      substr_count($b, '/') <=> substr_count($a, '/'));
    foreach ($dirs as $dir) {
      $this->removeDirIfEmpty($root, $dir);
    }
  }

  /**
   * The droost-authored skill directories droost no longer ships.
   *
   * Read-only: what a sweep would consider, for a status or doctor report.
   *
   * @param string $root
   *   The project root.
   *
   * @return list<string>
   *   Skill directory names under `.claude/skills/`, sorted.
   */
  public function retiredSkills(string $root): array {
    $emitted = [];
    foreach ($this->skills->getSkills() as $skill) {
      $emitted[$skill->name] = TRUE;
    }
    return $this->retired($root, $emitted);
  }

  /**
   * Removes the retired skills droost wrote and nobody has touched since.
   *
   * Before 0.7.0 nothing but `droost:uninstall` ever removed a skill, so a
   * site kept every skill droost had once written — including the guidance
   * skills it stopped shipping. The rule is deliberately narrow, because the
   * failure it must never have is deleting something that is not ours:
   *
   * - only a directory carrying droost's sentinel is a candidate;
   * - only one holding exactly SKILL.md and the sentinel is removed — a
   *   directory holding anything else is kept and reported;
   * - a SKILL.md whose recorded hash no longer matches was edited, and is
   *   kept (sentinels written before 0.7.0 record no hash, but install
   *   always overwrote those files, so no edit to them ever survived);
   * - a symlinked directory is never followed;
   * - and the whole sweep refuses when there is nothing to emit, because a
   *   skills directory that cannot be found looks exactly like one that is
   *   empty, and would retire every skill droost wrote.
   *
   * @param string $root
   *   The project root.
   * @param array<string, true> $emitted
   *   The skill names this install wrote, keyed by name.
   * @param \Droost\Engine\Harness\InstallResult $result
   *   The result accumulator.
   */
  private function sweepRetired(string $root, array $emitted, InstallResult $result): void {
    $retired = $this->retired($root, $emitted);
    if ($retired === []) {
      return;
    }
    if ($emitted === []) {
      $result->addWarning(sprintf('No droost skills to write, so %d droost-authored skill(s) under .claude/skills were left in place: an unreadable skills directory would otherwise retire every one of them.', count($retired)));
      return;
    }
    foreach ($retired as $name) {
      $dir = '.claude/skills/' . $name;
      $absolute = $root . '/' . $dir;
      if (is_link($absolute)) {
        $result->addWarning(sprintf('%s is a retired droost skill behind a symlink; left in place.', $dir));
        continue;
      }
      $entries = array_values(array_diff(scandir($absolute) ?: [], ['.', '..']));
      sort($entries);
      if ($entries !== self::SKILL_FILES) {
        $result->addWarning(sprintf('%s is a retired droost skill that holds other files; left in place.', $dir));
        continue;
      }
      $recorded = SkillMdWriter::recordedHash((string) file_get_contents($absolute . '/' . self::SENTINEL));
      if ($recorded !== NULL && !hash_equals($recorded, (string) hash_file('sha256', $absolute . '/SKILL.md'))) {
        $result->addWarning(sprintf('%s is a retired droost skill that was edited after droost wrote it; left in place.', $dir));
        continue;
      }
      unlink($absolute . '/SKILL.md');
      unlink($absolute . '/' . self::SENTINEL);
      rmdir($absolute);
      $result->addRemoved($dir . ' (retired skill)');
    }
  }

  /**
   * The sentinel-marked skill directories not in the emitted set.
   *
   * @param string $root
   *   The project root.
   * @param array<string, true> $emitted
   *   The skill names currently shipped, keyed by name.
   *
   * @return list<string>
   *   Directory names, sorted.
   */
  private function retired(string $root, array $emitted): array {
    $names = [];
    foreach (glob($root . '/.claude/skills/*', GLOB_ONLYDIR) ?: [] as $absolute) {
      $name = basename($absolute);
      if (!isset($emitted[$name]) && is_file($absolute . '/' . self::SENTINEL)) {
        $names[] = $name;
      }
    }
    sort($names);
    return $names;
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
