<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Scaffold;

use Droost\Engine\Scaffold\Blueprint\ViewsHandlerBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the Views-handler blueprint (handler + paired data-alter).
 */
#[CoversClass(ViewsHandlerBlueprint::class)]
final class ViewsHandlerBlueprintTest extends TestCase {

  /**
   * The temporary app root.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appRoot = sys_get_temp_dir() . '/droost_vh_' . uniqid();
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
   * A field run emits the handler and its paired views_data_alter class.
   */
  public function testFieldEmitsHandlerAndPairedDataAlter(): void {
    $result = $this->generate([
      'plugin-type' => 'field',
      'id' => 'my_field',
      'class' => 'MyField',
      'views-table' => 'node_field_data',
    ], FALSE);

    $this->assertContains('modules/mymod/src/Plugin/views/field/MyField.php', $result->created);
    $this->assertContains('modules/mymod/src/Hook/MyFieldViewsData.php', $result->created);

    $handler = (string) file_get_contents($this->appRoot . '/modules/mymod/src/Plugin/views/field/MyField.php');
    $this->assertStringContainsString("#[ViewsField('my_field')]", $handler);
    $this->assertStringContainsString('extends FieldPluginBase', $handler);
    $this->assertStringContainsString('public function render(ResultRow $values): string', $handler);

    $dataAlter = (string) file_get_contents($this->appRoot . '/modules/mymod/src/Hook/MyFieldViewsData.php');
    $this->assertStringContainsString("#[Hook('views_data_alter')]", $dataAlter);
    $this->assertStringContainsString("\$data['node_field_data']", $dataAlter);
    $this->assertStringContainsString("'field' => [", $dataAlter);
  }

  /**
   * Filter and sort runs coexist — distinct classes, both files planned.
   */
  public function testFilterAndSortVariantsUseTheirSubdirs(): void {
    $filter = $this->generate(['plugin-type' => 'filter', 'id' => 'my_filter', 'class' => 'MyFilter'], TRUE);
    $this->assertContains('modules/mymod/src/Plugin/views/filter/MyFilter.php', $filter->created);
    $this->assertContains('modules/mymod/src/Hook/MyFilterViewsData.php', $filter->created);

    $sort = $this->generate(['plugin-type' => 'sort', 'id' => 'my_sort', 'class' => 'MySort'], TRUE);
    $this->assertContains('modules/mymod/src/Plugin/views/sort/MySort.php', $sort->created);
  }

  /**
   * A dry run plans both files and writes nothing.
   */
  public function testDryRunPlansWithoutWriting(): void {
    $result = $this->generate(['plugin-type' => 'field', 'id' => 'dry_field'], TRUE);
    $this->assertCount(2, $result->created);
    $this->assertFileDoesNotExist($this->appRoot . '/modules/mymod/src/Plugin/views/field/DryField.php');
  }

  /**
   * An unknown plugin type is refused, naming the three variants.
   */
  public function testUnknownVariantIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('field, filter, or sort');
    (new ViewsHandlerBlueprint())->generate(
      new ScaffoldContext($this->appRoot, 'mymod', 'modules/mymod', ['plugin-type' => 'bogus'], TRUE),
      new ScaffoldResult(),
    );
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
  private function generate(array $inputs, bool $dryRun): ScaffoldResult {
    $result = new ScaffoldResult();
    (new ViewsHandlerBlueprint())->generate(
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
