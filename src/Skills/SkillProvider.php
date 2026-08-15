<?php

declare(strict_types=1);

namespace Droost\Engine\Skills;

use Droost\Engine\Guidelines\GuidelineProvider;

/**
 * Lists the harness-neutral skills for the per-harness renderers.
 *
 * Skills come from two sources, with no content duplication: dedicated skill
 * files under `guidelines/skills/*.md` (frontmatter `name` + `description`),
 * and the existing deep-dive topics (each surfaced as a `drupal-<topic>` skill
 * whose trigger is the topic's one-line summary).
 *
 * Riding the topics rather than copying them is what keeps the two corpora
 * from drifting — and it means skills inherit the guideline catalog's site
 * scoping for free: a site without media never gets a media skill written to
 * its harness.
 */
final readonly class SkillProvider {

  /**
   * Constructs a SkillProvider.
   *
   * @param string $skillsDir
   *   Absolute path to the dedicated skill files directory.
   * @param \Droost\Engine\Guidelines\GuidelineProvider $guidelines
   *   The guideline provider (supplies topic content).
   */
  public function __construct(
    private string $skillsDir,
    private GuidelineProvider $guidelines,
  ) {}

  /**
   * Returns every renderable skill.
   *
   * @return array<int, \Droost\Engine\Skills\Skill>
   *   The skills (dedicated skill files first, then topic-derived skills).
   */
  public function getSkills(): array {
    $skills = [];
    foreach (glob($this->skillsDir . '/*.md') ?: [] as $path) {
      $skills[] = $this->parse((string) file_get_contents($path), basename($path, '.md'));
    }
    foreach ($this->guidelines->listTopics() as $topic) {
      $body = $this->guidelines->getTopic($topic['name']) ?? '';
      $skills[] = new Skill('drupal-' . $topic['name'], $topic['summary'], $body);
    }
    return $skills;
  }

  /**
   * Parses a skill file's frontmatter and body.
   *
   * @param string $content
   *   The raw file content.
   * @param string $fallbackName
   *   The name to use if frontmatter omits one.
   *
   * @return \Droost\Engine\Skills\Skill
   *   The parsed skill.
   */
  private function parse(string $content, string $fallbackName): Skill {
    $name = $fallbackName;
    $description = '';
    $body = $content;
    if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', $content, $m) === 1) {
      $body = ltrim($m[2]);
      foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
        if (preg_match('/^(name|description):\s*(.*)$/', trim($line), $kv) === 1) {
          $value = $this->scalarValue(trim($kv[2]));
          if ($kv[1] === 'name') {
            $name = $value === '' ? $name : $value;
          }
          else {
            $description = $value;
          }
        }
      }
    }
    return new Skill($name, $description, $body);
  }

  /**
   * Normalises a YAML scalar value: unquotes it and ignores block indicators.
   *
   * Strips one layer of matching quotes (so a quoted description is not
   * double-wrapped when re-rendered into a harness skill file) and treats a
   * block-scalar indicator ("|"/">") as empty, since the block body is not
   * parsed here.
   *
   * @param string $value
   *   The raw value after the "key:".
   *
   * @return string
   *   The normalised scalar.
   */
  private function scalarValue(string $value): string {
    if (preg_match('/^[|>][+-]?$/', $value) === 1) {
      return '';
    }
    if (strlen($value) < 2) {
      return $value;
    }
    $quote = $value[0];
    if (($quote !== '"' && $quote !== "'") || $value[strlen($value) - 1] !== $quote) {
      return $value;
    }
    $inner = substr($value, 1, -1);
    return $quote === '"'
      ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner)
      : str_replace("''", "'", $inner);
  }

}
