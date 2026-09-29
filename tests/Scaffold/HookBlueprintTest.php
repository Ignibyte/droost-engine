<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Scaffold;

use Droost\Engine\Scaffold\Blueprint\HookBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the hook blueprint's emitted test file.
 */
#[CoversClass(HookBlueprint::class)]
final class HookBlueprintTest extends TestCase {

  /**
   * The temporary app root.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appRoot = sys_get_temp_dir() . '/droost_hb_' . uniqid();
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
   * A long hook name cannot push the emitted docblock past 80 columns.
   *
   * The emitted test's summary used to interpolate the method and hook
   * names, so a hook like language_fallback_candidates_alter produced a
   * docblock line phpcs rejects — in a file whose whole point is being
   * green by default. The names now live only in the assertions, whose
   * lines phpcs does not hold to the comment limit.
   */
  public function testLongHookNamesStayWithinTheCommentLimit(): void {
    $result = $this->generate([
      'hook' => 'language_fallback_candidates_alter',
    ]);

    $testFile = NULL;
    foreach ($result->created as $relative) {
      if (str_contains($relative, '/tests/')) {
        $testFile = $relative;
      }
    }
    $this->assertIsString($testFile, 'the blueprint emitted a test');

    $source = (string) file_get_contents($this->appRoot . '/' . $testFile);
    foreach (explode("\n", $source) as $number => $line) {
      if (str_contains(ltrim($line), '*')) {
        $this->assertLessThanOrEqual(
          80,
          strlen($line),
          sprintf('comment line %d fits phpcs: %s', $number + 1, $line),
        );
      }
    }
    // The behaviour is unchanged: the assertions still name the hook.
    $this->assertStringContainsString("'language_fallback_candidates_alter'", $source);
    $this->assertStringContainsString('getAttributes(Hook::class)', $source);
  }

  /**
   * A second implementation of a hook core invokes per module is refused.
   *
   * P6 run 22 scaffolded a second #[Hook('preprocess_views_view')] class in
   * a module whose filter hooks already had one. The blueprint reported
   * success, the class's test passed, and the first render of /camps threw
   * core's "should not implement preprocess_views_view more than once"
   * (F-150). A preprocess hook in a class, and hook_theme in the .module
   * file, are each found; nothing is written.
   */
  public function testSecondPerModuleImplementationIsRefused(): void {
    $this->put('modules/mymod/src/Hook/FilterHooks.php', "<?php\n\nnamespace Drupal\\mymod\\Hook;\n\nuse Drupal\\Core\\Hook\\Attribute\\Hook;\n\nfinal class FilterHooks {\n\n  #[Hook('preprocess_views_view')]\n  public function onPreprocessViewsView(array &\$variables): void {}\n\n}\n");
    $this->put('modules/mymod/mymod.module', "<?php\n\nfunction mymod_theme(): array {\n  return [];\n}\n");
    $this->put('modules/mymod/src/Help/HelpHooks.php', "<?php\n\nnamespace Drupal\\mymod\\Help;\n\nfinal class HelpHooks {\n\n  #[\\Drupal\\Core\\Hook\\Attribute\\Hook(hook: 'help')]\n  public function onHelp(): string {\n    return '';\n  }\n\n}\n");
    $asked = [
      'preprocess_views_view' => 'modules/mymod/src/Hook/FilterHooks.php',
      'theme' => 'modules/mymod/mymod.module',
      'help' => 'modules/mymod/src/Help/HelpHooks.php',
    ];
    foreach ($asked as $hook => $where) {
      try {
        $this->generate(['hook' => $hook, 'class' => 'PageHooks']);
        $this->fail("a second $hook was scaffolded");
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString("mymod already implements $hook, in $where", $e->getMessage());
        $this->assertStringContainsString('should not implement', $e->getMessage());
      }
    }
    $this->assertFileDoesNotExist($this->appRoot . '/modules/mymod/src/Hook/PageHooks.php');
  }

  /**
   * A hook every implementation of which runs is scaffolded beside another.
   *
   * Core's invokeAll() runs each of a module's implementations of
   * form_alter, so a second is allowed; so is a preprocess hook the module
   * does not have yet.
   */
  public function testHooksCoreInvokesForAllStillScaffold(): void {
    $this->put('modules/mymod/src/Hook/FormHooks.php', "<?php\n\nnamespace Drupal\\mymod\\Hook;\n\nuse Drupal\\Core\\Hook\\Attribute\\Hook;\n\nfinal class FormHooks {\n\n  #[Hook('form_alter')]\n  public function onFormAlter(): void {}\n\n  #[Hook('preprocess_views_view')]\n  public function onPreprocessViewsView(): void {}\n\n}\n");
    $this->assertNotEmpty($this->generate(['hook' => 'form_alter', 'class' => 'MoreFormHooks'])->created);
    $this->assertNotEmpty($this->generate(['hook' => 'preprocess_node', 'class' => 'NodeHooks'])->created);
  }

  /**
   * Writes a fixture file under the app root.
   *
   * @param string $relative
   *   The path relative to the app root.
   * @param string $content
   *   The file content.
   */
  private function put(string $relative, string $content): void {
    $path = $this->appRoot . '/' . $relative;
    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0777, TRUE);
    }
    file_put_contents($path, $content);
  }

  /**
   * Runs the blueprint over the fixture app root.
   *
   * @param array<string, string> $inputs
   *   The scaffold inputs.
   *
   * @return \Droost\Engine\Scaffold\ScaffoldResult
   *   The result.
   */
  private function generate(array $inputs): ScaffoldResult {
    $result = new ScaffoldResult();
    (new HookBlueprint())->generate(
      new ScaffoldContext($this->appRoot, 'mymod', 'modules/mymod', $inputs, FALSE),
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
