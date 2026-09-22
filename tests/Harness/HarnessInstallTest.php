<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Harness;

use Droost\Engine\Harness\AgentsHarnessInstaller;
use Droost\Engine\Harness\ClaudeHarnessInstaller;
use Droost\Engine\Harness\CodexHarnessInstaller;
use Droost\Engine\Harness\DroostBlock;
use Droost\Engine\Harness\GeminiHarnessInstaller;
use Droost\Engine\Harness\HarnessRegistry;
use Droost\Engine\Harness\InstallResult;
use Droost\Engine\Harness\Markers;
use Droost\Engine\Harness\OpencodeHarnessInstaller;
use Droost\Engine\Harness\QwenHarnessInstaller;
use Droost\Engine\Skills\SkillMdWriter;
use Droost\Engine\Skills\SkillProvider;
use Droost\Engine\Tests\Site\FakeSite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the harness install end to end, against a real project directory.
 *
 * The harness had no tests of its own before 0.7.0, which is how two things
 * could hang off one switch unnoticed: the guidelines, and the only road by
 * which a build pipeline's AGENTS.md block reaches four of the editors.
 */
#[CoversClass(HarnessRegistry::class)]
#[CoversClass(ClaudeHarnessInstaller::class)]
#[CoversClass(AgentsHarnessInstaller::class)]
#[CoversClass(DroostBlock::class)]
#[CoversClass(SkillMdWriter::class)]
final class HarnessInstallTest extends TestCase {

  /**
   * The pipeline's own AGENTS.md block, which the harness must never touch.
   */
  private const string WORKFLOW_BLOCK = "<!-- BEGIN DROOST WORKFLOW -->\nBuilds start with /droost:workflow:start.\n<!-- END DROOST WORKFLOW -->";

  /**
   * The project root under test.
   */
  private string $root;

  /**
   * The directory the skill provider reads.
   */
  private string $skillsDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $base = sys_get_temp_dir() . '/droost-harness-' . bin2hex(random_bytes(6));
    $this->root = $base . '/project';
    $this->skillsDir = $base . '/skills';
    mkdir($this->root, 0777, TRUE);
    mkdir($this->skillsDir, 0777, TRUE);
    file_put_contents($this->skillsDir . '/documenting-changes.md', "---\nname: documenting-changes\ndescription: Keep the wiki true.\n---\nWrite it down.\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeTree(dirname($this->root));
    parent::tearDown();
  }

  /**
   * A second install with nothing changed writes byte-identical files.
   */
  public function testSecondInstallChangesNothing(): void {
    $this->install('all');
    $first = $this->snapshot();
    $this->install('all');

    $this->assertSame($first, $this->snapshot());
  }

  /**
   * The block body is replaced in place, under the marker it always had.
   *
   * The markers are deliberately NOT renamed. A rename needs a migration, and
   * a half-done migration leaves two blocks or two imports; this proves the
   * old guidelines block is rewritten where it stands, that the pipeline's
   * block beside it survives byte for byte, and that the user's text does.
   */
  public function testBlockBodyIsReplacedInPlaceUnderTheSameMarker(): void {
    $old = Markers::MD_BEGIN . "\n# Droost guidelines\n\nCall `droost_guidelines` (pass a `topic`).\n" . Markers::MD_END;
    file_put_contents($this->root . '/AGENTS.md', "My own notes.\n\n" . $old . "\n\n" . self::WORKFLOW_BLOCK . "\n");

    $this->install('agents');
    $agents = (string) file_get_contents($this->root . '/AGENTS.md');

    $this->assertSame(1, substr_count($agents, Markers::MD_BEGIN), 'exactly one droost block');
    $this->assertSame(1, substr_count($agents, '<!-- BEGIN DROOST WORKFLOW -->'), 'exactly one workflow block');
    $this->assertStringContainsString(self::WORKFLOW_BLOCK, $agents, 'the workflow block is untouched');
    $this->assertStringStartsWith("My own notes.\n", $agents, 'the user text is untouched');
    $this->assertLessThan(strpos($agents, '<!-- BEGIN DROOST WORKFLOW -->'), strpos($agents, Markers::MD_BEGIN), 'the block kept its place');
    $this->assertStringContainsString('## Use Droost first', $agents);
    $this->assertStringNotContainsString('droost_guidelines', $agents);
  }

  /**
   * Every editor is pointed at AGENTS.md, with no switch that could stop it.
   *
   * For Claude, Qwen, Gemini and opencode this pointer is the only way the
   * build pipeline's AGENTS.md block reaches the agent at all.
   */
  public function testEveryEditorPointerIsWrittenUnconditionally(): void {
    $this->install('all');

    $claude = (string) file_get_contents($this->root . '/CLAUDE.md');
    $this->assertSame(1, substr_count($claude, '@AGENTS.md'), 'CLAUDE.md imports AGENTS.md exactly once');
    $this->assertStringNotContainsString('guidelines', $claude);
    foreach (['QWEN.md', 'GEMINI.md'] as $file) {
      $this->assertStringContainsString('`./AGENTS.md`', (string) file_get_contents($this->root . '/' . $file), $file . ' points at AGENTS.md');
    }
    $opencode = json_decode((string) file_get_contents($this->root . '/opencode.json'), TRUE);
    $this->assertIsArray($opencode);
    $this->assertSame(['./AGENTS.md'], $opencode['instructions']);
    $this->assertFileExists($this->root . '/.claude/skills/documenting-changes/SKILL.md');
  }

  /**
   * A retired skill droost wrote and nobody touched is removed.
   *
   * This is the case every existing site is in: a sentinel written before
   * 0.7.0, which records no hash, beside the SKILL.md droost wrote.
   */
  public function testSweepRemovesUntouchedRetiredSkill(): void {
    $this->plantSkill('drupal-methodology', "Legacy guidance.\n", "Droost-authored skill; safe to delete via `drush droost:uninstall`.\n");

    $result = $this->install('claude');

    $this->assertDirectoryDoesNotExist($this->root . '/.claude/skills/drupal-methodology');
    $this->assertContains('.claude/skills/drupal-methodology (retired skill)', $result->removed);
    $this->assertFileExists($this->root . '/.claude/skills/documenting-changes/SKILL.md', 'the skill still shipped is written, not swept');
  }

  /**
   * A retired skill holding a file droost did not write is kept and reported.
   */
  public function testSweepKeepsRetiredSkillHoldingOtherFiles(): void {
    $this->plantSkill('drupal-sdc', "Legacy.\n");
    file_put_contents($this->root . '/.claude/skills/drupal-sdc/notes.md', "Mine.\n");

    $result = $this->install('claude');

    $this->assertFileExists($this->root . '/.claude/skills/drupal-sdc/notes.md');
    $this->assertFileExists($this->root . '/.claude/skills/drupal-sdc/SKILL.md');
    $this->assertNotEmpty(array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'drupal-sdc') && str_contains($w, 'other files')));
  }

  /**
   * A retired skill edited since droost wrote it is kept, on its hash.
   */
  public function testSweepKeepsRetiredSkillEditedAfterDroostWroteIt(): void {
    $written = "What droost wrote.\n";
    $this->plantSkill('using-droost', $written, SkillMdWriter::sentinel($written));
    file_put_contents($this->root . '/.claude/skills/using-droost/SKILL.md', "What the user made of it.\n");

    $result = $this->install('claude');

    $this->assertFileExists($this->root . '/.claude/skills/using-droost/SKILL.md');
    $this->assertNotEmpty(array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'using-droost') && str_contains($w, 'edited')));
  }

  /**
   * A skill without droost's sentinel is never a candidate.
   */
  public function testSweepNeverTouchesUnmarkedSkills(): void {
    mkdir($this->root . '/.claude/skills/my-skill', 0777, TRUE);
    file_put_contents($this->root . '/.claude/skills/my-skill/SKILL.md', "Mine.\n");

    $result = $this->install('claude');

    $this->assertFileExists($this->root . '/.claude/skills/my-skill/SKILL.md');
    $this->assertSame([], array_values(array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'my-skill'))));
  }

  /**
   * Nothing to emit is not a licence to retire everything.
   *
   * A skills directory that cannot be found reads exactly like an empty one;
   * sweeping on it would delete every skill droost ever wrote, including the
   * ones still meant to ship.
   */
  public function testSweepRefusesWhenThereIsNothingToEmit(): void {
    $this->plantSkill('documenting-changes', "Still shipped.\n");
    $this->skillsDir .= '/missing';

    $result = $this->install('claude');

    $this->assertFileExists($this->root . '/.claude/skills/documenting-changes/SKILL.md');
    $this->assertNotEmpty(array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'left in place')));
  }

  /**
   * A retired skill behind a symlink is never followed.
   */
  public function testSweepNeverFollowsSymlinkedSkill(): void {
    $target = dirname($this->root) . '/elsewhere';
    mkdir($target, 0777, TRUE);
    file_put_contents($target . '/SKILL.md', "Linked.\n");
    file_put_contents($target . '/' . SkillMdWriter::SENTINEL, SkillMdWriter::sentinel("Linked.\n"));
    mkdir($this->root . '/.claude/skills', 0777, TRUE);
    symlink($target, $this->root . '/.claude/skills/drupal-linked');

    $result = $this->install('claude');

    $this->assertTrue(is_link($this->root . '/.claude/skills/drupal-linked'), 'the link is left in place');
    $this->assertFileExists($target . '/SKILL.md', 'and so is what it points at');
    $this->assertNotEmpty(array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'symlink')));
  }

  /**
   * The read-only report names what a sweep would consider, and removes none.
   */
  public function testRetiredSkillsReportsWithoutRemoving(): void {
    $this->plantSkill('drupal-theming', "Legacy.\n");
    $this->plantSkill('drupal-site-planning', "Legacy.\n");

    $installer = new ClaudeHarnessInstaller(new SkillProvider($this->skillsDir), $this->writer());

    $this->assertSame(['drupal-site-planning', 'drupal-theming'], $installer->retiredSkills($this->root));
    $this->assertDirectoryExists($this->root . '/.claude/skills/drupal-theming');
  }

  /**
   * The sentinel records the SKILL.md hash; a pre-0.7.0 one records none.
   */
  public function testSentinelRecordsSkillHash(): void {
    $sentinel = SkillMdWriter::sentinel("Body.\n");

    $this->assertSame(hash('sha256', "Body.\n"), SkillMdWriter::recordedHash($sentinel));
    $this->assertNull(SkillMdWriter::recordedHash("Droost-authored skill; safe to delete.\n"));
  }

  /**
   * What droost is, stamped with the version, and naming no removed tool.
   */
  public function testDroostBlockNamesNoRemovedTool(): void {
    $body = (new DroostBlock(new FakeSite([], '11.4.2')))->body();

    $this->assertStringStartsWith('# Droost — Drupal 11.4.2, PHP ', $body);
    $this->assertStringContainsString('## Use Droost first', $body);
    foreach (['droost_guidelines', 'droost_module_patterns'] as $removed) {
      $this->assertStringNotContainsString($removed, $body);
    }
    $this->assertStringStartsWith('# Droost — Drupal version unknown, PHP ', (new DroostBlock(new FakeSite([], '')))->body());
  }

  /**
   * Runs one install against the project root.
   *
   * @param string $harness
   *   The harness selection.
   *
   * @return \Droost\Engine\Harness\InstallResult
   *   The result.
   */
  private function install(string $harness): InstallResult {
    $registry = new HarnessRegistry(
      new DroostBlock(new FakeSite([], '11.4.2')),
      new AgentsHarnessInstaller(),
      new ClaudeHarnessInstaller(new SkillProvider($this->skillsDir), $this->writer()),
      new CodexHarnessInstaller(),
      new QwenHarnessInstaller(),
      new GeminiHarnessInstaller(),
      new OpencodeHarnessInstaller(),
    );
    return $registry->install($this->root, ['command' => 'ddev', 'args' => ['drush', 'mcp:server']], $harness);
  }

  /**
   * The SKILL.md writer every install uses.
   *
   * @return \Droost\Engine\Skills\SkillMdWriter
   *   A writer stamping a fixed version.
   */
  private function writer(): SkillMdWriter {
    return new SkillMdWriter('0.7.0', new FakeSite([], '11.4.2'));
  }

  /**
   * Plants a droost-authored skill directory, as an older release left it.
   *
   * @param string $name
   *   The skill name.
   * @param string $body
   *   The SKILL.md content.
   * @param string|null $sentinel
   *   The sentinel content; NULL for one recording this body's hash.
   */
  private function plantSkill(string $name, string $body, ?string $sentinel = NULL): void {
    $dir = $this->root . '/.claude/skills/' . $name;
    mkdir($dir, 0777, TRUE);
    file_put_contents($dir . '/SKILL.md', $body);
    file_put_contents($dir . '/' . SkillMdWriter::SENTINEL, $sentinel ?? SkillMdWriter::sentinel($body));
  }

  /**
   * Every file under the project root, with its content.
   *
   * @return array<string, string>
   *   Relative path to content, sorted.
   */
  private function snapshot(): array {
    $files = [];
    $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
      if ($item instanceof \SplFileInfo && $item->isFile()) {
        $files[substr($item->getPathname(), strlen($this->root) + 1)] = (string) file_get_contents($item->getPathname());
      }
    }
    ksort($files);
    return $files;
  }

  /**
   * Removes a directory tree without ever following a symlink.
   *
   * @param string $path
   *   The directory.
   */
  private function removeTree(string $path): void {
    if (is_link($path) || is_file($path)) {
      unlink($path);
      return;
    }
    if (!is_dir($path)) {
      return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
      $this->removeTree($path . '/' . $entry);
    }
    rmdir($path);
  }

}
