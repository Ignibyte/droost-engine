<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Scaffold;

use Droost\Engine\Scaffold\Blueprint\KernelTestBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the kernel-test blueprint.
 */
#[CoversClass(KernelTestBlueprint::class)]
final class KernelTestBlueprintTest extends TestCase {

  /**
   * The temporary app root.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appRoot = sys_get_temp_dir() . '/droost_kt_' . uniqid();
    mkdir($this->appRoot, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    self::rrmdir($this->appRoot);
    parent::tearDown();
  }

  /**
   * A run emits a runnable kernel test at the suite root.
   */
  public function testEmitsKernelTest(): void {
    $result = $this->generate(['id' => 'examples_smoke', 'modules' => 'system,user,mymod']);

    $relative = 'modules/mymod/tests/src/Kernel/ExamplesSmokeTest.php';
    $this->assertContains($relative, $result->created);

    $source = (string) file_get_contents($this->appRoot . '/' . $relative);
    $this->assertStringContainsString('declare(strict_types=1);', $source);
    $this->assertStringContainsString('namespace Drupal\Tests\mymod\Kernel;', $source);
    $this->assertStringContainsString('final class ExamplesSmokeTest extends KernelTestBase', $source);
    $this->assertStringContainsString("#[Group('mymod')]", $source);
    // Omitting this is deprecated in Drupal 11.3 and throws in Drupal 12, and
    // the deprecation is silenced in most CI configs — so a scaffolded test
    // without it would look fine right up until the major bump.
    $this->assertStringContainsString('#[RunTestsInSeparateProcesses]', $source);
    $this->assertStringContainsString("protected static \$modules = ['system', 'user', 'mymod'];", $source);
    // A real assertion, not a placeholder: it passes as soon as the chain is
    // right, and the boot it depends on is the part that can actually fail.
    $this->assertStringContainsString("\$moduleHandler->moduleExists('mymod')", $source);
    // PHPStan level max needs the array shape on the static property.
    $this->assertStringContainsString('@var array<int, string>', $source);
    // The trap the blueprint exists to teach.
    $this->assertStringContainsString('does NOT pull in', $source);
  }

  /**
   * Entries that cannot name a module are dropped, not emitted.
   *
   * The machineName() helper maps "-" to "_" before filtering, so
   * punctuation-only entries survive as "_"/"___" and digits survive as
   * "123". Emitting those makes the kernel throw "Unavailable module" at
   * boot, a long way from the typo that caused it.
   */
  public function testUnusableModuleNamesAreDropped(): void {
    $this->generate(['id' => 'thing', 'modules' => '-,--,---,123,4bad,ok9,system']);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/tests/src/Kernel/ThingTest.php');
    $this->assertStringContainsString("protected static \$modules = ['ok9', 'system', 'mymod'];", $source);
  }

  /**
   * The modules list keeps its order and is normalised entry by entry.
   */
  public function testModulesListIsNormalisedInOrder(): void {
    $this->generate(['id' => 'thing', 'modules' => ' System , user,, user , My-Mod ,!!!']);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/tests/src/Kernel/ThingTest.php');
    // Trimmed, lowercased, hyphens to underscores, junk and duplicates
    // dropped, order preserved, target appended last.
    $this->assertStringContainsString("protected static \$modules = ['system', 'user', 'my_mod', 'mymod'];", $source);
  }

  /**
   * The target module is appended when the caller leaves it out.
   */
  public function testTargetModuleIsAppendedWhenMissing(): void {
    $this->generate(['id' => 'thing', 'modules' => 'system,user']);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/tests/src/Kernel/ThingTest.php');
    $this->assertStringContainsString("protected static \$modules = ['system', 'user', 'mymod'];", $source);
  }

  /**
   * An empty modules option yields just the target module.
   */
  public function testEmptyModulesDefaultsToTarget(): void {
    $this->generate(['id' => 'thing']);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/tests/src/Kernel/ThingTest.php');
    $this->assertStringContainsString("protected static \$modules = ['mymod'];", $source);
  }

  /**
   * An explicit class input is honoured, with the Test suffix appended.
   */
  public function testExplicitClassGetsTheTestSuffix(): void {
    $result = $this->generate(['id' => 'thing', 'class' => 'CustomThing']);
    $this->assertContains('modules/mymod/tests/src/Kernel/CustomThingTest.php', $result->created);
  }

  /**
   * Inputs that sanitise to an empty id are rejected, not written.
   */
  public function testEmptyDerivedIdThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->generate(['id' => '***']);
  }

  /**
   * A second run over an existing file skips it (never overwrites).
   */
  public function testRerunSkipsExistingFile(): void {
    $this->generate(['id' => 'thing']);
    $second = $this->generate(['id' => 'thing']);
    $this->assertContains('modules/mymod/tests/src/Kernel/ThingTest.php', $second->skipped);
  }

  /**
   * A dry run plans the file and writes nothing.
   */
  public function testDryRunWritesNothing(): void {
    $result = $this->generate(['id' => 'thing'], TRUE);
    $this->assertContains('modules/mymod/tests/src/Kernel/ThingTest.php', $result->created);
    $this->assertFileDoesNotExist($this->appRoot . '/modules/mymod/tests/src/Kernel/ThingTest.php');
  }

  /**
   * A "*\/" in --label cannot break out of the docblock and inject code.
   */
  public function testLabelCannotBreakOutOfDocblock(): void {
    $this->generate([
      'id' => 'evil',
      'label' => 'X */ const INJECTED = 1; /*',
    ]);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/tests/src/Kernel/EvilTest.php');

    $hasConst = FALSE;
    foreach (token_get_all($source) as $token) {
      if (is_array($token) && $token[0] === T_CONST) {
        $hasConst = TRUE;
      }
    }
    $this->assertFalse($hasConst, 'Label content must not break out of the docblock into executable code.');
    $this->assertStringContainsString('* /', $source);
  }

  /**
   * A quote in a module name cannot break out of the $modules literal.
   */
  public function testModuleNamesCannotBreakOutOfTheArrayLiteral(): void {
    $this->generate(['id' => 'sneaky', 'modules' => "x'); system('id'); //"]);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/tests/src/Kernel/SneakyTest.php');

    $calls = 0;
    foreach (token_get_all($source) as $token) {
      if (is_array($token) && $token[0] === T_STRING && $token[1] === 'system') {
        $calls++;
      }
    }
    $this->assertSame(0, $calls, 'A module name must not close the array literal and inject a call.');
  }

  /**
   * Runs the blueprint over the fixture app root.
   *
   * @param array<string, string> $inputs
   *   The scaffold inputs.
   * @param bool $dryRun
   *   Whether to dry-run.
   *
   * @return \Droost\Engine\Scaffold\ScaffoldResult
   *   The result.
   */
  private function generate(array $inputs, bool $dryRun = FALSE): ScaffoldResult {
    $result = new ScaffoldResult();
    (new KernelTestBlueprint())->generate(
      new ScaffoldContext($this->appRoot, 'mymod', 'modules/mymod', $inputs, $dryRun),
      $result,
    );
    return $result;
  }

  /**
   * Recursively removes a directory tree.
   *
   * @param string $dir
   *   The directory.
   */
  private static function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }
      $path = $dir . '/' . $item;
      if (is_dir($path)) {
        self::rrmdir($path);
      }
      else {
        unlink($path);
      }
    }
    rmdir($dir);
  }

}
