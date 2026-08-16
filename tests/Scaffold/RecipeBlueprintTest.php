<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Scaffold;

use Droost\Engine\Scaffold\Blueprint\RecipeBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for the recipe blueprint.
 *
 * The load-bearing assertion here is WHERE the file lands. A recipe written
 * inside the docroot is valid YAML that Drupal will never find, so a test that
 * only parsed the content would pass on a recipe nobody can apply.
 */
#[CoversClass(RecipeBlueprint::class)]
final class RecipeBlueprintTest extends TestCase {

  /**
   * The temporary project root (the parent of the app root).
   */
  private string $projectRoot;

  /**
   * The temporary app root (the docroot).
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->projectRoot = sys_get_temp_dir() . '/droost_recipe_' . uniqid();
    $this->appRoot = $this->projectRoot . '/web';
    mkdir($this->appRoot, 0777, TRUE);
    // ProjectRoot recognises the parent only when it carries a marker; without
    // one it falls back to the app root, which is a case of its own below.
    file_put_contents($this->projectRoot . '/composer.json', "{}\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    self::rrmdir($this->projectRoot);
    parent::tearDown();
  }

  /**
   * The recipe lands ABOVE the docroot, and says so in the reported path.
   */
  public function testWritesToTheProjectRootNotTheDocroot(): void {
    $result = $this->generate(['id' => 'my_recipe', 'label' => 'My Recipe'], FALSE);

    $this->assertSame(['../recipes/my_recipe/recipe.yml'], $result->created);
    $this->assertFileExists($this->projectRoot . '/recipes/my_recipe/recipe.yml');
    $this->assertFileDoesNotExist($this->appRoot . '/recipes/my_recipe/recipe.yml');
  }

  /**
   * The generated file is valid YAML with the keys a recipe needs.
   */
  public function testEmitsValidRecipeYaml(): void {
    $this->generate([
      'id' => 'my_recipe',
      'label' => "Chad's Recipe",
      'description' => 'Does a thing.',
      'modules' => 'node, views,  block ',
      'recipes' => 'drupal_cms_starter',
    ], FALSE);

    $recipe = Yaml::parse((string) file_get_contents($this->projectRoot . '/recipes/my_recipe/recipe.yml'));
    $this->assertIsArray($recipe);
    // The apostrophe has to survive as data, not end the scalar.
    $this->assertSame("Chad's Recipe", $recipe['name']);
    $this->assertSame('Does a thing.', $recipe['description']);
    $this->assertSame(['node', 'views', 'block'], $recipe['install']);
    $this->assertSame(['drupal_cms_starter'], $recipe['recipes']);
    $config = $recipe['config'] ?? NULL;
    $this->assertIsArray($config);
    $this->assertFalse($config['strict']);
    // "type" carries consequences the scaffold cannot assert (a Site recipe
    // asserts screenshot metadata), so it is left for a human to add.
    $this->assertArrayNotHasKey('type', $recipe);
  }

  /**
   * An empty list is commented out, not emitted as an empty sequence.
   */
  public function testEmptyListsAreCommentedRatherThanEmpty(): void {
    $this->generate(['id' => 'bare'], FALSE);

    $raw = (string) file_get_contents($this->projectRoot . '/recipes/bare/recipe.yml');
    $recipe = Yaml::parse($raw);
    $this->assertIsArray($recipe);
    // `install: []` is valid and would apply silently, installing nothing —
    // indistinguishable from a recipe that meant to.
    $this->assertArrayNotHasKey('install', $recipe);
    $this->assertArrayNotHasKey('recipes', $recipe);
    $this->assertStringContainsString('# install:', $raw);
  }

  /**
   * Without a project marker, the recipe falls back to the app root.
   */
  public function testFallsBackToTheAppRootWhenThereIsNoProjectAbove(): void {
    unlink($this->projectRoot . '/composer.json');
    $result = $this->generate(['id' => 'flat'], FALSE);

    // No "../" prefix, because here there is no above.
    $this->assertSame(['recipes/flat/recipe.yml'], $result->created);
    $this->assertFileExists($this->appRoot . '/recipes/flat/recipe.yml');
  }

  /**
   * The id defaults to the module name.
   */
  public function testIdDefaultsToTheModule(): void {
    $result = $this->generate([], FALSE);

    $this->assertSame(['../recipes/mymod/recipe.yml'], $result->created);
  }

  /**
   * A dry run reports the path and writes nothing.
   */
  public function testDryRunWritesNothing(): void {
    $result = $this->generate(['id' => 'my_recipe'], TRUE);

    $this->assertSame(['../recipes/my_recipe/recipe.yml'], $result->created);
    $this->assertFileDoesNotExist($this->projectRoot . '/recipes/my_recipe/recipe.yml');
  }

  /**
   * An existing recipe is skipped, never overwritten.
   */
  public function testExistingRecipeIsSkipped(): void {
    mkdir($this->projectRoot . '/recipes/my_recipe', 0777, TRUE);
    file_put_contents($this->projectRoot . '/recipes/my_recipe/recipe.yml', "name: Mine\n");

    $result = $this->generate(['id' => 'my_recipe'], FALSE);

    $this->assertSame([], $result->created);
    $this->assertSame(['../recipes/my_recipe/recipe.yml'], $result->skipped);
    $this->assertSame("name: Mine\n", file_get_contents($this->projectRoot . '/recipes/my_recipe/recipe.yml'));
  }

  /**
   * Runs the blueprint against the temporary roots.
   *
   * @param array<string, string> $inputs
   *   The blueprint inputs.
   * @param bool $dryRun
   *   Whether to run as a dry run.
   *
   * @return \Droost\Engine\Scaffold\ScaffoldResult
   *   The result.
   */
  private function generate(array $inputs, bool $dryRun): ScaffoldResult {
    $result = new ScaffoldResult();
    (new RecipeBlueprint())->generate(
      new ScaffoldContext($this->appRoot, 'mymod', 'modules/mymod', $inputs, $dryRun),
      $result,
    );
    return $result;
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
