<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Scaffold;

use Droost\Engine\Scaffold\Blueprint\FunctionalTestBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the functional-test blueprint.
 */
#[CoversClass(FunctionalTestBlueprint::class)]
final class FunctionalTestBlueprintTest extends TestCase {

  /**
   * The temporary app root.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appRoot = sys_get_temp_dir() . '/droost_ft_' . uniqid();
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
   * A run emits a runnable functional test at the suite root.
   */
  public function testEmitsFunctionalTest(): void {
    $result = $this->generate(['id' => 'examples_functional_smoke', 'modules' => 'system,user,mymod']);

    $relative = 'modules/mymod/tests/src/Functional/ExamplesFunctionalSmokeTest.php';
    $this->assertContains($relative, $result->created);

    $source = (string) file_get_contents($this->appRoot . '/' . $relative);
    $this->assertStringContainsString('declare(strict_types=1);', $source);
    $this->assertStringContainsString('namespace Drupal\Tests\mymod\Functional;', $source);
    $this->assertStringContainsString('final class ExamplesFunctionalSmokeTest extends BrowserTestBase', $source);
    $this->assertStringContainsString("#[Group('mymod')]", $source);
    $this->assertStringContainsString('#[RunTestsInSeparateProcesses]', $source);
    // The classic failure of this tier: BrowserTestBase requires the theme.
    $this->assertStringContainsString("protected \$defaultTheme = 'stark';", $source);
    $this->assertStringContainsString("protected static \$modules = ['system', 'user', 'mymod'];", $source);
    // Green-by-default: a page every site serves, then the module check.
    $this->assertStringContainsString("drupalGet('user/login')", $source);
    $this->assertStringContainsString('statusCodeEquals(200)', $source);
    $this->assertStringContainsString("moduleExists('mymod')", $source);
    // PHPStan level max needs the array shape on the static property.
    $this->assertStringContainsString('@var array<int, string>', $source);
    // The environment this tier needs, named in the emitted docblock.
    $this->assertStringContainsString('SIMPLETEST_BASE_URL', $source);
    // The tier asymmetry, stated the right way round: the functional
    // installer resolves dependencies (module_installer->install(..., TRUE)),
    // where the kernel chain does not.
    $this->assertStringContainsString('dependency resolution ON', $source);
  }

  /**
   * The theme option lands in $defaultTheme; the default is stark.
   */
  public function testThemeOptionIsHonoured(): void {
    $this->generate(['id' => 'themed', 'theme' => 'olivero']);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/tests/src/Functional/ThemedTest.php');
    $this->assertStringContainsString("protected \$defaultTheme = 'olivero';", $source);
  }

  /**
   * A theme that sanitises to junk is rejected, not emitted.
   */
  public function testUnusableThemeThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->generate(['id' => 'thing', 'theme' => '123']);
  }

  /**
   * The shared modules normalisation applies here too.
   */
  public function testModulesListIsNormalisedInOrder(): void {
    $this->generate(['id' => 'thing', 'modules' => ' System , user,, user , My-Mod ,!!!']);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/tests/src/Functional/ThingTest.php');
    $this->assertStringContainsString("protected static \$modules = ['system', 'user', 'my_mod', 'mymod'];", $source);
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
    $this->assertContains('modules/mymod/tests/src/Functional/ThingTest.php', $second->skipped);
  }

  /**
   * A "*\/" in --label cannot break out of the docblock and inject code.
   */
  public function testLabelCannotBreakOutOfDocblock(): void {
    $this->generate([
      'id' => 'evil',
      'label' => 'X */ const INJECTED = 1; /*',
    ]);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/tests/src/Functional/EvilTest.php');

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
    (new FunctionalTestBlueprint())->generate(
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
