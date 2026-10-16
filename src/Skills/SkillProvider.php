<?php

declare(strict_types=1);

namespace Droost\Engine\Skills;

/**
 * Lists the harness-neutral skills for the per-harness renderers.
 *
 * Skills are the dedicated skill files droost ships (`<skillsDir>/*.md`,
 * frontmatter `name` + `description`), and nothing else. Until 0.7.0 every
 * guideline topic was also surfaced as a `drupal-<topic>` skill; droost no
 * longer ships guidance, and across the measured runs no agent ever opened
 * one of those skills.
 */
final readonly class SkillProvider {

  /**
   * Constructs a SkillProvider.
   *
   * @param string $skillsDir
   *   Absolute path to the dedicated skill files directory.
   */
  public function __construct(
    private string $skillsDir,
  ) {}

  /**
   * Returns every renderable skill.
   *
   * An empty list is a real answer only when the directory holds no skill
   * files; `glob()` also returns nothing for a directory that does not
   * exist. Callers that act on "nothing to emit" — the Claude installer's
   * stale-skill sweep — refuse to, for exactly that reason.
   *
   * @return array<int, \Droost\Engine\Skills\Skill>
   *   The skills, in file-name order.
   */
  public function getSkills(): array {
    $skills = [];
    $paths = glob($this->skillsDir . '/*.md') ?: [];
    sort($paths);
    foreach ($paths as $path) {
      $skill = $this->parse((string) file_get_contents($path), basename($path, '.md'));
      if ($skill !== NULL) {
        $skills[] = $skill;
      }
    }
    return $skills;
  }

  /**
   * A name a skill may have: it becomes a directory under `.claude/skills/`.
   */
  private const string NAME = '/^[a-z0-9][a-z0-9_-]*$/';

  /**
   * Parses a skill file's frontmatter and body.
   *
   * The frontmatter name becomes a directory, so one that is not a plain
   * name (`../x`, a slash, a capital) falls back to the file's own name, and
   * a skill with neither is not rendered.
   *
   * @param string $content
   *   The raw file content.
   * @param string $fallbackName
   *   The name to use if frontmatter omits one or gives one that is no name.
   *
   * @return \Droost\Engine\Skills\Skill|null
   *   The parsed skill, or NULL when it has no usable name.
   */
  private function parse(string $content, string $fallbackName): ?Skill {
    $name = $fallbackName;
    $description = '';
    $body = $content;
    if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', $content, $m) === 1) {
      $body = ltrim($m[2]);
      foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
        if (preg_match('/^(name|description):\s*(.*)$/', trim($line), $kv) === 1) {
          $value = $this->scalarValue(trim($kv[2]));
          if ($kv[1] === 'name') {
            $name = preg_match(self::NAME, $value) === 1 ? $value : $name;
          }
          else {
            $description = $value;
          }
        }
      }
    }
    return preg_match(self::NAME, $name) === 1 ? new Skill($name, $description, $body) : NULL;
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
