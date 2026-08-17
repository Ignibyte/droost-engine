<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Scaffold;

use Droost\Engine\Scaffold\Blueprint\Ckeditor5PluginBlueprint;
use Droost\Engine\Scaffold\Blueprint\MediaSourceBlueprint;
use Droost\Engine\Scaffold\Blueprint\MigrateBlueprint;
use Droost\Engine\Scaffold\Blueprint\PluginDeriverBlueprint;
use Droost\Engine\Scaffold\BlueprintInterface;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for the P3.1 blueprints.
 *
 * Every generated file must be syntactically valid PHP or parseable YAML —
 * a scaffold that emits a parse error is worse than no scaffold, because the
 * developer debugs their own typo instead of ours.
 */
#[CoversClass(PluginDeriverBlueprint::class)]
#[CoversClass(MediaSourceBlueprint::class)]
#[CoversClass(MigrateBlueprint::class)]
#[CoversClass(Ckeditor5PluginBlueprint::class)]
final class NewBlueprintsTest extends TestCase {

  /**
   * The temporary app root.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appRoot = sys_get_temp_dir() . '/droost_bp_' . uniqid();
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
   * The deriver blueprint emits BOTH halves of the pattern.
   */
  public function testPluginDeriverEmitsDeriverAndPlugin(): void {
    $result = $this->generate(new PluginDeriverBlueprint(), ['id' => 'my_thing', 'label' => 'My Thing']);

    $this->assertSame([
      'modules/mymod/src/Plugin/Derivative/MyThingDeriver.php',
      'modules/mymod/src/Plugin/Block/MyThingBlock.php',
    ], $result->created);

    $deriver = $this->read('modules/mymod/src/Plugin/Derivative/MyThingDeriver.php');
    $this->assertStringContainsString('implements ContainerDeriverInterface', $deriver);
    $block = $this->read('modules/mymod/src/Plugin/Block/MyThingBlock.php');
    // The load-bearing line: without deriver:, the deriver class never runs.
    $this->assertStringContainsString('deriver: \\Drupal\\mymod\\Plugin\\Derivative\\MyThingDeriver::class', $block);
    $this->assertStringContainsString("id: 'my_thing'", $block);
  }

  /**
   * A migrate SOURCE comes with the migration that runs it.
   *
   * The load-bearing half. Migrations are discovered from `migrations/*.yml`,
   * never from plugin classes, so a source emitted on its own would not appear
   * in `drush migrate:status` at all — a file that looks finished and does
   * nothing. Same reasoning as the deriver above.
   */
  public function testMigrateSourceEmitsTheMigrationThatRunsIt(): void {
    $result = $this->generate(
      new MigrateBlueprint(),
      ['plugin-type' => 'source', 'id' => 'legacy_items', 'label' => 'Legacy Items'],
    );

    $this->assertSame([
      'modules/mymod/src/Plugin/migrate/source/LegacyItems.php',
      'modules/mymod/migrations/mymod_legacy_items.yml',
    ], $result->created);

    $source = $this->read('modules/mymod/src/Plugin/migrate/source/LegacyItems.php');
    // SqlBase would need a second database connection in settings.php before
    // anything could run; SourcePluginBase runs as generated.
    $this->assertStringContainsString('extends SourcePluginBase', $source);
    // Narrow deliberately: the docblock EXPLAINS why not SqlBase, so a blanket
    // substring check fails on the explanation rather than the code.
    $this->assertStringNotContainsString('extends SqlBase', $source);
    $this->assertStringNotContainsString('use Drupal\\migrate\\Plugin\\migrate\\source\\SqlBase;', $source);
    $this->assertStringContainsString("#[MigrateSource(id: 'legacy_items')]", $source);
    $this->assertStringContainsString('public function getIds()', $source);

    $migration = $this->read('modules/mymod/migrations/mymod_legacy_items.yml');
    $this->assertStringContainsString('plugin: legacy_items', $migration);
    $this->assertStringContainsString("plugin: 'entity:node'", $migration);
  }

  /**
   * A migrate PROCESS plugin is emitted alone — it needs no migration.
   */
  public function testMigrateProcessIsEmittedAlone(): void {
    $result = $this->generate(
      new MigrateBlueprint(),
      ['plugin-type' => 'process', 'id' => 'tidy_title'],
    );

    $this->assertSame(
      ['modules/mymod/src/Plugin/migrate/process/TidyTitle.php'],
      $result->created,
    );
    $process = $this->read('modules/mymod/src/Plugin/migrate/process/TidyTitle.php');
    $this->assertStringContainsString('extends ProcessPluginBase', $process);
    $this->assertStringContainsString('public function transform(', $process);
  }

  /**
   * An unknown plugin type is refused by name.
   */
  public function testMigrateRefusesAnUnknownPluginType(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('--plugin-type=source or process');
    $this->generate(new MigrateBlueprint(), ['plugin-type' => 'destination']);
  }

  /**
   * The media source declares its field types and a default thumbnail.
   */
  public function testMediaSourceDeclaresItsContract(): void {
    $result = $this->generate(new MediaSourceBlueprint(), ['id' => 'product_code', 'label' => 'Product Code']);

    $this->assertSame(['modules/mymod/src/Plugin/media/Source/ProductCode.php'], $result->created);
    $source = $this->read('modules/mymod/src/Plugin/media/Source/ProductCode.php');
    $this->assertStringContainsString("id: 'product_code'", $source);
    $this->assertStringContainsString('allowed_field_types:', $source);
    // Without it, an underivable thumbnail renders broken rather than a
    // placeholder — the failure this default exists to prevent.
    $this->assertStringContainsString('default_thumbnail_filename:', $source);
    $this->assertStringContainsString('getMetadataAttributes', $source);
  }

  /**
   * The CKEditor 5 blueprint emits the PHP half and a parseable declaration.
   */
  public function testCkeditor5EmitsPluginAndDeclaration(): void {
    $result = $this->generate(new Ckeditor5PluginBlueprint(), ['id' => 'highlight', 'label' => 'Highlight']);

    $this->assertSame([
      'modules/mymod/src/Plugin/CKEditor5Plugin/Highlight.php',
      'modules/mymod/mymod.ckeditor5.yml',
    ], $result->created);

    $declaration = Yaml::parse($this->read('modules/mymod/mymod.ckeditor5.yml'));
    $this->assertIsArray($declaration);
    // Namespaced by module, so two modules' "highlight" plugins do not collide.
    $plugin = $declaration['mymod_highlight'] ?? NULL;
    $this->assertIsArray($plugin);
    $drupal = $plugin['drupal'] ?? NULL;
    $this->assertIsArray($drupal);
    $this->assertSame('Highlight', $drupal['label']);
    // An element declared but not produced is harmless; one produced but not
    // declared is silently stripped, so the scaffold must ship elements.
    $this->assertNotEmpty($drupal['elements']);
  }

  /**
   * No JavaScript is emitted, and the declaration says why.
   */
  public function testCkeditor5EmitsNoJavaScript(): void {
    $result = $this->generate(new Ckeditor5PluginBlueprint(), ['id' => 'highlight']);

    foreach ($result->created as $path) {
      $this->assertStringEndsNotWith('.js', $path);
    }
    // The gap is documented where the developer will look for it.
    $this->assertStringContainsString(
      'emits no JavaScript',
      $this->read('modules/mymod/mymod.ckeditor5.yml'),
    );
  }

  /**
   * Every emitted PHP file parses.
   */
  public function testEmittedPhpIsSyntacticallyValid(): void {
    $blueprints = [
      new PluginDeriverBlueprint(),
      new MediaSourceBlueprint(),
      new Ckeditor5PluginBlueprint(),
    ];
    foreach ($blueprints as $blueprint) {
      $result = $this->generate($blueprint, ['id' => 'thing_' . $blueprint->getId(), 'label' => "It's fine"]);
      foreach ($result->created as $path) {
        if (!str_ends_with($path, '.php')) {
          continue;
        }
        $file = $this->appRoot . '/' . $path;
        $output = [];
        $status = 0;
        exec(sprintf('php -l %s 2>&1', escapeshellarg($file)), $output, $status);
        $this->assertSame(0, $status, $path . ': ' . implode("\n", $output));
      }
    }
  }

  /**
   * A label containing a quote cannot break out of the generated code.
   */
  public function testLabelsAreEscaped(): void {
    $this->generate(new MediaSourceBlueprint(), ['id' => 'quoted', 'label' => "O'Brien's \\ source"]);
    $source = $this->read('modules/mymod/src/Plugin/media/Source/Quoted.php');
    $output = [];
    $status = 0;
    exec(sprintf('php -l %s 2>&1', escapeshellarg($this->appRoot . '/modules/mymod/src/Plugin/media/Source/Quoted.php')), $output, $status);
    $this->assertSame(0, $status, implode("\n", $output));
    $this->assertStringContainsString('Quoted', $source);
  }

  /**
   * Every blueprint reports a stable id and a non-empty description.
   */
  public function testIdentity(): void {
    $expected = [
      'plugin-deriver' => new PluginDeriverBlueprint(),
      'media-source' => new MediaSourceBlueprint(),
      'ckeditor5-plugin' => new Ckeditor5PluginBlueprint(),
    ];
    foreach ($expected as $id => $blueprint) {
      $this->assertInstanceOf(BlueprintInterface::class, $blueprint);
      $this->assertSame($id, $blueprint->getId());
      $this->assertNotSame('', $blueprint->description());
    }
  }

  /**
   * Runs a blueprint against the temporary app root.
   *
   * @param \Droost\Engine\Scaffold\BlueprintInterface $blueprint
   *   The blueprint.
   * @param array<string, string> $inputs
   *   The inputs.
   *
   * @return \Droost\Engine\Scaffold\ScaffoldResult
   *   The result.
   */
  private function generate(BlueprintInterface $blueprint, array $inputs): ScaffoldResult {
    $result = new ScaffoldResult();
    $blueprint->generate(
      new ScaffoldContext($this->appRoot, 'mymod', 'modules/mymod', $inputs, FALSE),
      $result,
    );
    return $result;
  }

  /**
   * Reads a generated file.
   *
   * @param string $relative
   *   The path relative to the app root.
   *
   * @return string
   *   The contents.
   */
  private function read(string $relative): string {
    return (string) file_get_contents($this->appRoot . '/' . $relative);
  }

  /**
   * Removes a directory tree.
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
