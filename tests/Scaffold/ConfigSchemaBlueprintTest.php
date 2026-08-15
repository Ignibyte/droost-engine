<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Scaffold;

use Symfony\Component\Yaml\Yaml;
use Droost\Engine\Scaffold\Blueprint\ConfigSchemaBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the config-schema blueprint.
 */
#[CoversClass(ConfigSchemaBlueprint::class)]
final class ConfigSchemaBlueprintTest extends TestCase {

  /**
   * The temporary app root.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appRoot = sys_get_temp_dir() . '/droost_cs_' . uniqid();
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
   * A default run emits a config_object schema and a matching install default.
   */
  public function testEmitsConfigObjectSchemaAndDefault(): void {
    $result = $this->generate(['label' => 'My settings'], FALSE);

    $this->assertContains('modules/mymod/config/schema/mymod.settings.schema.yml', $result->created);
    $this->assertContains('modules/mymod/config/install/mymod.settings.yml', $result->created);

    $schema = Yaml::parse((string) file_get_contents($this->appRoot . '/modules/mymod/config/schema/mymod.settings.schema.yml'));
    $this->assertIsArray($schema);
    $object = $schema['mymod.settings'] ?? NULL;
    $this->assertIsArray($object);
    $this->assertSame('config_object', $object['type']);
    $this->assertSame('My settings', $object['label']);
    $mapping = $object['mapping'] ?? NULL;
    $this->assertIsArray($mapping);
    $this->assertSame(['label', 'enabled', 'items_per_page', 'tags', 'advanced'], array_keys($mapping));
    // Nested shapes: a sequence of strings and a nested mapping. Narrow each
    // level so there is no offset access on mixed (phpstan level max).
    $tags = $mapping['tags'] ?? NULL;
    $this->assertIsArray($tags);
    $sequence = $tags['sequence'] ?? NULL;
    $this->assertIsArray($sequence);
    $this->assertSame('string', $sequence['type']);
    $advanced = $mapping['advanced'] ?? NULL;
    $this->assertIsArray($advanced);
    $advancedMapping = $advanced['mapping'] ?? NULL;
    $this->assertIsArray($advancedMapping);
    $timeout = $advancedMapping['timeout'] ?? NULL;
    $this->assertIsArray($timeout);
    $this->assertSame('integer', $timeout['type']);

    // The install default carries exactly the schema's keys — the precondition
    // for module install validating under strict config-schema checking.
    $install = Yaml::parse((string) file_get_contents($this->appRoot . '/modules/mymod/config/install/mymod.settings.yml'));
    $this->assertIsArray($install);
    $this->assertSame(['label', 'enabled', 'items_per_page', 'tags', 'advanced'], array_keys($install));
    $this->assertTrue($install['enabled']);
    $this->assertSame(10, $install['items_per_page']);
  }

  /**
   * The id input names the settings object after the module prefix.
   */
  public function testIdNamesTheSettingsObject(): void {
    $result = $this->generate(['id' => 'email'], FALSE);
    $this->assertContains('modules/mymod/config/schema/mymod.email.schema.yml', $result->created);
    $schema = Yaml::parse((string) file_get_contents($this->appRoot . '/modules/mymod/config/schema/mymod.email.schema.yml'));
    $this->assertIsArray($schema);
    $this->assertArrayHasKey('mymod.email', $schema);
  }

  /**
   * A single quote in the label cannot break the YAML scalar.
   */
  public function testLabelIsYamlEscaped(): void {
    $this->generate(['label' => "Bob's settings"], FALSE);
    $schema = Yaml::parse((string) file_get_contents($this->appRoot . '/modules/mymod/config/schema/mymod.settings.schema.yml'));
    $this->assertIsArray($schema);
    $object = $schema['mymod.settings'] ?? NULL;
    $this->assertIsArray($object);
    $this->assertSame("Bob's settings", $object['label']);
  }

  /**
   * A second run over existing files skips them (never overwrites).
   */
  public function testRerunSkipsExistingFiles(): void {
    $this->generate([], FALSE);
    $second = $this->generate([], FALSE);
    $this->assertContains('modules/mymod/config/schema/mymod.settings.schema.yml', $second->skipped);
    $this->assertContains('modules/mymod/config/install/mymod.settings.yml', $second->skipped);
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
    (new ConfigSchemaBlueprint())->generate(
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
