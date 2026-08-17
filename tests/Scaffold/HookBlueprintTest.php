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
