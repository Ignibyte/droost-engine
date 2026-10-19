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
   * The MCP server droost's installer passes on DDEV.
   */
  private const array SERVER = ['command' => 'ddev', 'args' => ['exec', 'php', 'vendor/bin/droost-mcp']];

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

    $this->install('all', self::SERVER);
    $withServer = $this->snapshot();
    $this->install('all', self::SERVER);
    $this->assertSame($withServer, $this->snapshot(), 'a second install with the server changes nothing either');
  }

  /**
   * With a server, every harness writes droost's entry beside its others.
   *
   * The shapes are the ones each client reads: `mcpServers` for Claude Code,
   * Gemini and Qwen; a fenced `[mcp_servers.droost]` table for Codex; a
   * `local` entry with the command as one list for opencode.
   */
  public function testInstallWritesTheDroostServerEverywhere(): void {
    file_put_contents($this->root . '/.mcp.json', (string) json_encode([
      'mcpServers' => ['playwright' => ['command' => 'npx']],
    ]));
    @mkdir($this->root . '/.codex');
    file_put_contents($this->root . '/.codex/config.toml', "model = \"x\"\n");
    file_put_contents($this->root . '/opencode.json', (string) json_encode(['theme' => 'dark']));

    $result = $this->install('all', self::SERVER);

    $this->assertSame(['command' => 'npx'], $this->entry('.mcp.json', 'mcpServers', 'playwright'), 'the user\'s server is kept');
    $this->assertSame(self::SERVER, $this->entry('.mcp.json', 'mcpServers', 'droost'));
    foreach (['.gemini/settings.json', '.qwen/settings.json'] as $file) {
      $this->assertSame(self::SERVER, $this->entry($file, 'mcpServers', 'droost'), $file);
    }
    $codex = (string) file_get_contents($this->root . '/.codex/config.toml');
    $this->assertStringContainsString('model = "x"', $codex);
    $this->assertStringContainsString("[mcp_servers.droost]\ncommand = \"ddev\"\nargs = [\"exec\", \"php\", \"vendor/bin/droost-mcp\"]", $codex);
    $this->assertSame(
      ['type' => 'local', 'command' => ['ddev', 'exec', 'php', 'vendor/bin/droost-mcp'], 'enabled' => TRUE],
      $this->entry('opencode.json', 'mcp', 'droost'),
    );
    $opencode = json_decode((string) file_get_contents($this->root . '/opencode.json'), TRUE);
    $this->assertIsArray($opencode);
    $this->assertSame('dark', $opencode['theme']);
    $this->assertContains('.mcp.json', $result->written);
  }

  /**
   * An empty object elsewhere in a config stays an object, both ways.
   *
   * Decoding to arrays turned another server's `"env": {}` into `"env": []`
   * on every install and uninstall, which its client may refuse.
   */
  public function testEmptyObjectsSurviveInstallAndUninstall(): void {
    $raw = '{"mcpServers":{"other":{"command":"x","args":[],"env":{}}},"x":{},"y":{"0":"a"}}';
    file_put_contents($this->root . '/.mcp.json', $raw);
    file_put_contents($this->root . '/opencode.json', '{"mcp":{"other":{"type":"local","command":["x"],"environment":{}}},"x":{}}');

    $this->install('all', self::SERVER);
    $this->assertInstanceOf(\stdClass::class, $this->node('.mcp.json', 'x'), 'a top-level {} is still an object');
    $this->assertInstanceOf(\stdClass::class, $this->node('opencode.json', 'x'));
    $this->assertInstanceOf(\stdClass::class, $this->node('.mcp.json', 'mcpServers', 'other', 'env'), 'a sibling server\'s env is still an object');
    $this->assertInstanceOf(\stdClass::class, $this->node('.mcp.json', 'y'), 'an object keyed like a list is still an object');
    $this->assertInstanceOf(\stdClass::class, $this->node('opencode.json', 'mcp', 'other', 'environment'));

    $this->registry()->uninstall($this->root, 'all');
    $this->assertInstanceOf(\stdClass::class, $this->node('.mcp.json', 'mcpServers', 'other', 'env'), 'and after uninstall');
    $this->assertInstanceOf(\stdClass::class, $this->node('.mcp.json', 'x'));
  }

  /**
   * What the user added to droost's own entry survives a re-install.
   */
  public function testUserKeysInDroostsEntrySurvive(): void {
    $env = ['DROOST_MCP_TIMEOUT' => '120'];
    file_put_contents($this->root . '/.mcp.json', (string) json_encode([
      'mcpServers' => ['droost' => ['command' => 'old', 'env' => $env]],
    ]));
    file_put_contents($this->root . '/opencode.json', (string) json_encode([
      'mcp' => ['droost' => ['type' => 'local', 'command' => ['old'], 'environment' => $env]],
    ]));

    $this->install('all', self::SERVER);

    $entry = $this->entry('.mcp.json', 'mcpServers', 'droost');
    $this->assertIsArray($entry);
    $this->assertSame('ddev', $entry['command']);
    $this->assertSame(self::SERVER['args'], $entry['args']);
    $this->assertSame(['DROOST_MCP_TIMEOUT' => '120'], $entry['env']);
    $opencode = $this->entry('opencode.json', 'mcp', 'droost');
    $this->assertIsArray($opencode);
    $this->assertSame(['DROOST_MCP_TIMEOUT' => '120'], $opencode['environment']);
    $this->assertSame(['ddev', 'exec', 'php', 'vendor/bin/droost-mcp'], $opencode['command']);
  }

  /**
   * A config already holding droost's entry is not rewritten.
   */
  public function testAnInstalledConfigKeepsItsFormatting(): void {
    $raw = "{\n  \"mcpServers\": {\n    \"droost\": {\"command\": \"ddev\", \"args\": [\"exec\", \"php\", \"vendor/bin/droost-mcp\"]}\n  }\n}\n";
    file_put_contents($this->root . '/.mcp.json', $raw);

    $this->install('claude', self::SERVER);

    $this->assertSame($raw, file_get_contents($this->root . '/.mcp.json'));
  }

  /**
   * A droost server Codex already has, in any spelling, is not defined twice.
   */
  public function testCodexHandWrittenDroostInAnySpellingIsLeftAlone(): void {
    $spellings = [
      "[mcp_servers.droost]\ncommand = \"x\"\n",
      "[mcp_servers.\"droost\"]\ncommand = \"x\"\n",
      "[ mcp_servers . droost ]\ncommand = \"x\"\n",
      "[mcp_servers.droost.env]\nA = \"1\"\n",
      "[mcp_servers]\ndroost = { command = \"x\" }\n",
      "[mcp_servers]\n\"droost\".command = \"x\"\n",
      "mcp_servers.droost.command = \"x\"\n",
    ];
    @mkdir($this->root . '/.codex');
    foreach ($spellings as $toml) {
      file_put_contents($this->root . '/.codex/config.toml', $toml);
      $result = $this->install('codex', self::SERVER);
      $this->assertSame($toml, file_get_contents($this->root . '/.codex/config.toml'), $toml);
      $this->assertNotSame([], array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'already defines mcp_servers.droost')), $toml);
    }

    $commented = "# [mcp_servers.droost] is ours now\n[mcp_servers.other]\ncommand = \"y\"\n";
    file_put_contents($this->root . '/.codex/config.toml', $commented);
    $this->install('codex', self::SERVER);
    $this->assertStringContainsString("[mcp_servers.droost]\ncommand = \"ddev\"", (string) file_get_contents($this->root . '/.codex/config.toml'), 'a comment or another server is not a definition');
  }

  /**
   * A config that is not valid JSON is left alone, and the install says so.
   */
  public function testMalformedConfigIsRefused(): void {
    file_put_contents($this->root . '/.mcp.json', '{"mcpServers": {');

    $result = $this->install('claude', self::SERVER);

    $this->assertSame('{"mcpServers": {', file_get_contents($this->root . '/.mcp.json'));
    $this->assertNotSame([], array_filter($result->warnings, static fn (string $w): bool => str_contains($w, '.mcp.json is present but not valid JSON')));
  }

  /**
   * Uninstall removes droost's entry and keeps the rest.
   */
  public function testUninstallRemovesTheServer(): void {
    file_put_contents($this->root . '/.mcp.json', (string) json_encode([
      'mcpServers' => ['playwright' => ['command' => 'npx']],
    ]));
    $this->install('all', self::SERVER);

    $this->registry()->uninstall($this->root, 'all');

    $this->assertNull($this->entry('.mcp.json', 'mcpServers', 'droost'));
    $this->assertSame(['command' => 'npx'], $this->entry('.mcp.json', 'mcpServers', 'playwright'));
    $this->assertStringNotContainsString('mcp_servers.droost', (string) @file_get_contents($this->root . '/.codex/config.toml'));
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
    $this->assertStringContainsString('## Use droost first', $agents);
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
   * Uninstall removes droost's skills as written, and nothing anyone added.
   */
  public function testUninstallKeepsWhatDroostDidNotWrite(): void {
    $this->install('claude');
    $this->plantSkill('drupal-notes', "Legacy.\n");
    file_put_contents($this->root . '/.claude/skills/drupal-notes/notes.md', "Mine.\n");
    $written = "What droost wrote.\n";
    $this->plantSkill('drupal-edited', $written, SkillMdWriter::sentinel($written));
    file_put_contents($this->root . '/.claude/skills/drupal-edited/SKILL.md', "Edited.\n");

    $result = $this->registry()->uninstall($this->root, 'all');

    $this->assertDirectoryDoesNotExist($this->root . '/.claude/skills/documenting-changes', 'an untouched droost skill is removed');
    $this->assertFileExists($this->root . '/.claude/skills/drupal-notes/notes.md', 'a user\'s file is never deleted');
    $this->assertFileExists($this->root . '/.claude/skills/drupal-edited/SKILL.md', 'an edited skill is kept');
    $this->assertNotEmpty(array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'drupal-notes') && str_contains($w, 'other files')));
    $this->assertNotEmpty(array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'drupal-edited') && str_contains($w, 'edited')));
  }

  /**
   * Uninstall never follows a symlinked skill, nor removes it.
   */
  public function testUninstallNeverFollowsSymlinkedSkill(): void {
    $target = dirname($this->root) . '/precious';
    mkdir($target, 0777, TRUE);
    file_put_contents($target . '/SKILL.md', "Linked.\n");
    file_put_contents($target . '/' . SkillMdWriter::SENTINEL, SkillMdWriter::sentinel("Linked.\n"));
    mkdir($this->root . '/.claude/skills', 0777, TRUE);
    symlink($target, $this->root . '/.claude/skills/drupal-linked');

    $result = $this->registry()->uninstall($this->root, 'all');

    $this->assertTrue(is_link($this->root . '/.claude/skills/drupal-linked'), 'the link is left in place');
    $this->assertFileExists($target . '/SKILL.md', 'and so is what it points at');
    $this->assertNotEmpty(array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'symlink')));
  }

  /**
   * A skill's name never takes its directory outside `.claude/skills`.
   */
  public function testSkillNameCannotEscapeTheSkillsDirectory(): void {
    file_put_contents($this->skillsDir . '/escape.md', "---\nname: ../../escaped\ndescription: x\n---\nBody.\n");
    file_put_contents($this->skillsDir . '/Bad Name.md', "No frontmatter.\n");

    $this->install('claude');

    $this->assertFileDoesNotExist(dirname($this->root) . '/escaped/SKILL.md');
    $this->assertFileDoesNotExist($this->root . '/escaped/SKILL.md');
    $this->assertFileExists($this->root . '/.claude/skills/escape/SKILL.md', 'the file\'s own name is used instead');
    $this->assertDirectoryDoesNotExist($this->root . '/.claude/skills/Bad Name', 'a skill with no usable name is not rendered');
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
   * An install with no server (`--no-mcp`) removes droost's MCP entry.
   *
   * Every other server and key is kept, and a file left holding nothing is
   * removed.
   */
  public function testInstallRemovesTheDroostServerEverywhere(): void {
    $server = ['command' => 'ddev', 'args' => ['drush', 'mcp:server']];
    file_put_contents($this->root . '/.mcp.json', (string) json_encode([
      'mcpServers' => ['droost' => $server, 'playwright' => ['command' => 'npx']],
    ]));
    @mkdir($this->root . '/.gemini');
    file_put_contents($this->root . '/.gemini/settings.json', (string) json_encode(['mcpServers' => ['droost' => $server]]));
    @mkdir($this->root . '/.codex');
    file_put_contents($this->root . '/.codex/config.toml', "model = \"x\"\n" . Markers::TOML_BEGIN . "\n[mcp_servers.droost]\ncommand = \"ddev\"\n" . Markers::TOML_END . "\n");
    file_put_contents($this->root . '/opencode.json', (string) json_encode([
      'mcp' => ['droost' => ['type' => 'local']],
      'theme' => 'dark',
    ]));

    $this->install('all');

    $mcp = json_decode((string) file_get_contents($this->root . '/.mcp.json'), TRUE);
    $this->assertIsArray($mcp);
    $this->assertIsArray($mcp['mcpServers'] ?? NULL);
    $this->assertSame(['playwright'], array_keys($mcp['mcpServers']), 'droost is gone, the user\'s server kept');
    $this->assertFileDoesNotExist($this->root . '/.gemini/settings.json', 'a file left holding nothing is removed');
    $codex = (string) file_get_contents($this->root . '/.codex/config.toml');
    $this->assertStringNotContainsString('mcp_servers.droost', $codex);
    $this->assertStringContainsString('model = "x"', $codex);
    $opencode = json_decode((string) file_get_contents($this->root . '/opencode.json'), TRUE);
    $this->assertIsArray($opencode);
    $this->assertArrayNotHasKey('mcp', $opencode);
    $this->assertSame('dark', $opencode['theme']);
    $this->assertSame(['./AGENTS.md'], $opencode['instructions']);
  }

  /**
   * What droost is, stamped with the version, and naming no removed tool.
   */
  public function testDroostBlockNamesNoRemovedTool(): void {
    $body = (new DroostBlock(new FakeSite('11.4.2')))->body();

    $this->assertStringStartsWith('# Droost — Drupal 11.4.2, PHP ', $body);
    $this->assertStringContainsString('## Use droost first', $body);
    // Removed tools, and since 2026-10-19 the drush door, MCP and the
    // Drupal-era tool ids: droost is one command reading files.
    foreach (['droost_guidelines', 'droost_module_patterns', 'droost_consult', 'droost_decide', 'mcp__', 'MCP', 'drush', 'droost:tool', 'droost_wiki', 'droost_symbol'] as $removed) {
      $this->assertStringNotContainsString($removed, $body);
    }
    foreach (['droost code:wiki', 'droost code:tree', 'droost search:arch'] as $command) {
      $this->assertStringContainsString($command, $body, 'it names the knowledge commands plan requires');
    }
    $this->assertStringStartsWith('# Droost — Drupal version unknown, PHP ', (new DroostBlock(new FakeSite('')))->body());
  }

  /**
   * Runs one install against the project root.
   *
   * @param string $harness
   *   The harness selection.
   * @param array{command: string, args: list<string>}|null $server
   *   droost's MCP server, or NULL for none.
   *
   * @return \Droost\Engine\Harness\InstallResult
   *   The result.
   */
  private function install(string $harness, ?array $server = NULL): InstallResult {
    return $this->registry()->install($this->root, $harness, $server);
  }

  /**
   * One entry of a JSON config's server map, or NULL when it is not there.
   *
   * @param string $file
   *   The config, relative to the project root.
   * @param string $parent
   *   The server map's key.
   * @param string $key
   *   The server's key.
   *
   * @return mixed
   *   The entry.
   */
  private function entry(string $file, string $parent, string $key): mixed {
    $data = json_decode((string) @file_get_contents($this->root . '/' . $file), TRUE);
    $servers = is_array($data) ? ($data[$parent] ?? NULL) : NULL;
    return is_array($servers) ? ($servers[$key] ?? NULL) : NULL;
  }

  /**
   * A value in a JSON config, decoded with objects as objects.
   *
   * @param string $file
   *   The config, relative to the project root.
   * @param string ...$path
   *   The keys to walk.
   *
   * @return mixed
   *   The value.
   */
  private function node(string $file, string ...$path): mixed {
    $value = json_decode((string) file_get_contents($this->root . '/' . $file), FALSE);
    foreach ($path as $key) {
      $this->assertInstanceOf(\stdClass::class, $value, $file . ': ' . $key . '\'s parent is an object');
      $value = $value->{$key} ?? NULL;
    }
    return $value;
  }

  /**
   * The registry every install and uninstall uses.
   *
   * @return \Droost\Engine\Harness\HarnessRegistry
   *   The registry, with every harness.
   */
  private function registry(): HarnessRegistry {
    return new HarnessRegistry(
      new DroostBlock(new FakeSite('11.4.2')),
      new AgentsHarnessInstaller(),
      new ClaudeHarnessInstaller(new SkillProvider($this->skillsDir), $this->writer()),
      new CodexHarnessInstaller(),
      new QwenHarnessInstaller(),
      new GeminiHarnessInstaller(),
      new OpencodeHarnessInstaller(),
    );
  }

  /**
   * The SKILL.md writer every install uses.
   *
   * @return \Droost\Engine\Skills\SkillMdWriter
   *   A writer stamping a fixed version.
   */
  private function writer(): SkillMdWriter {
    return new SkillMdWriter('0.7.0', new FakeSite('11.4.2'));
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
