<?php

declare(strict_types=1);

namespace Droost\Engine\Skills;

use Droost\Engine\Site\ExtensionLocatorInterface;
use Droost\Engine\Support\CoreVersion;

/**
 * The single authority for rendering and writing skills as SKILL.md.
 *
 * Emits the agentskills.io / Claude Agent Skill frontmatter — `name` +
 * `description` always, plus the derivable optionals `license` and `metadata`
 * (the Droost version + running core major) — so Droost's skills interoperate
 * with the emerging Surge / ai_skills ecosystem AND install as native Claude
 * skills from ONE format. Emission never authors: the bodies come from the
 * skill files droost ships (SkillProvider), rendered unchanged.
 */
final readonly class SkillMdWriter {

  /**
   * The pack license (the drupal.org ecosystem license).
   */
  private const string LICENSE = 'GPL-2.0-or-later';

  /**
   * Sentinel file marking a skill directory as Droost-authored.
   */
  public const string SENTINEL = '.droost-skill';

  /**
   * How the sentinel records the SKILL.md it vouches for.
   */
  private const string HASH_LINE = '/^sha256: ([0-9a-f]{64})$/m';

  /**
   * Constructs a SkillMdWriter.
   *
   * @param string $ownVersion
   *   The corpus owner's version, stamped into the metadata block. '' when it
   *   cannot be determined — a dev checkout has no release version, and
   *   claiming one would be worse than omitting the key.
   * @param \Droost\Engine\Site\ExtensionLocatorInterface $site
   *   The site, for the core major the skills were rendered against.
   */
  public function __construct(
    private string $ownVersion,
    private ExtensionLocatorInterface $site,
  ) {}

  /**
   * Renders a skill as a SKILL.md document.
   *
   * @param \Droost\Engine\Skills\Skill $skill
   *   The skill.
   *
   * @return string
   *   The SKILL.md content.
   */
  public function render(Skill $skill): string {
    // Quote the description as a YAML double-quoted scalar so a colon, hash, or
    // quote in a topic summary can't break the frontmatter.
    $description = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $skill->description) . '"';
    $lines = [
      '---',
      'name: ' . $skill->name,
      'description: ' . $description,
      'license: ' . self::LICENSE,
    ];
    $metadata = $this->metadata();
    if ($metadata !== []) {
      $lines[] = 'metadata:';
      foreach ($metadata as $key => $value) {
        $lines[] = '  ' . $key . ': ' . $value;
      }
    }
    $lines[] = '---';
    $lines[] = '';
    $lines[] = rtrim($skill->body);
    $lines[] = '';
    return implode("\n", $lines);
  }

  /**
   * Writes a skill to `<targetDir>/<name>/SKILL.md` with a sentinel.
   *
   * Never overwrites a skill directory Droost did not author unless $force is
   * set (the same guard the Claude installer uses).
   *
   * @param \Droost\Engine\Skills\Skill $skill
   *   The skill.
   * @param string $targetDir
   *   The directory that will hold `<name>/SKILL.md`.
   * @param bool $force
   *   Overwrite a non-Droost-managed skill directory.
   *
   * @return array{name: string, written: bool, reason: string}
   *   The per-skill outcome.
   */
  public function write(Skill $skill, string $targetDir, bool $force): array {
    $dir = rtrim($targetDir, '/') . '/' . $skill->name;
    $sentinel = $dir . '/' . self::SENTINEL;
    if (is_dir($dir) && !is_file($sentinel) && !$force) {
      return ['name' => $skill->name, 'written' => FALSE, 'reason' => 'exists and is not Droost-managed'];
    }
    if (!is_dir($dir) && !mkdir($dir, 0777, TRUE) && !is_dir($dir)) {
      return ['name' => $skill->name, 'written' => FALSE, 'reason' => 'could not create the directory'];
    }
    $content = $this->render($skill);
    file_put_contents($dir . '/SKILL.md', $content);
    file_put_contents($sentinel, self::sentinel($content));
    return ['name' => $skill->name, 'written' => TRUE, 'reason' => ''];
  }

  /**
   * The sentinel for a SKILL.md, recording the hash of what was written.
   *
   * Before 0.7.0 the sentinel was a fixed string, so nothing could tell a
   * skill someone had edited from one droost wrote — and a cleanup that cannot
   * tell the two apart has to treat every edit as disposable. The hash is what
   * lets a later sweep keep an edited skill.
   *
   * @param string $skillMd
   *   The SKILL.md content being written.
   *
   * @return string
   *   The sentinel file content.
   */
  public static function sentinel(string $skillMd): string {
    return "Droost-authored skill; safe to delete via `drush droost:uninstall`.\n"
      . 'sha256: ' . hash('sha256', $skillMd) . "\n";
  }

  /**
   * The SKILL.md hash a sentinel recorded, or NULL for a pre-0.7.0 sentinel.
   *
   * @param string $sentinel
   *   The sentinel file content.
   *
   * @return string|null
   *   The recorded sha256, or NULL when none was recorded.
   */
  public static function recordedHash(string $sentinel): ?string {
    return preg_match(self::HASH_LINE, $sentinel, $matches) === 1 ? $matches[1] : NULL;
  }

  /**
   * The derivable metadata block (omitting anything unavailable).
   *
   * @return array<string, string>
   *   The metadata key/value pairs.
   */
  private function metadata(): array {
    $metadata = [];
    if ($this->ownVersion !== '') {
      $metadata['droost'] = $this->ownVersion;
    }
    $major = CoreVersion::major($this->site->coreVersion());
    if ($major !== '') {
      $metadata['drupal_core_major'] = $major;
    }
    return $metadata;
  }

}
