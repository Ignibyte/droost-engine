<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Scaffold;

use Droost\Engine\Scaffold\Blueprint\AccessHandlerBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the access-handler blueprint.
 */
#[CoversClass(AccessHandlerBlueprint::class)]
final class AccessHandlerBlueprintTest extends TestCase {

  /**
   * The temporary app root.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appRoot = sys_get_temp_dir() . '/droost_ah_' . uniqid();
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
   * A run emits a cache-aware handler with per-operation permissions.
   */
  public function testEmitsPerOperationHandler(): void {
    $result = $this->generate(['id' => 'example_profile', 'label' => 'Example Profile']);

    $relative = 'modules/mymod/src/ExampleProfileAccessControlHandler.php';
    $this->assertContains($relative, $result->created);

    $source = (string) file_get_contents($this->appRoot . '/' . $relative);
    $this->assertStringContainsString('declare(strict_types=1);', $source);
    $this->assertStringContainsString('namespace Drupal\mymod;', $source);
    $this->assertStringContainsString('final class ExampleProfileAccessControlHandler extends EntityAccessControlHandler', $source);

    // The per-operation grants: Drupal's operation names are view/update/delete
    // while the permission convention is view/edit/delete.
    $this->assertStringContainsString("'view' => AccessResult::allowedIfHasPermission(\$account, 'view example_profile')", $source);
    $this->assertStringContainsString("'update' => AccessResult::allowedIfHasPermission(\$account, 'edit example_profile')", $source);
    $this->assertStringContainsString("'delete' => AccessResult::allowedIfHasPermission(\$account, 'delete example_profile')", $source);
    $this->assertStringContainsString("\$permissions = ['create example_profile'];", $source);
    $this->assertStringContainsString("AccessResult::allowedIfHasPermissions(\$account, \$permissions, 'OR')", $source);
    // The admin permission is never hardcoded: an entity type whose
    // admin_permission is not literally "administer <id>" (core's taxonomy_term
    // uses "administer taxonomy") must still be administrable.
    $this->assertStringNotContainsString("'administer example_profile'", $source);

    // Core forbids deleting an unsaved entity before any permission check;
    // overriding checkAccess() replaces that rule, so the template restates it.
    $this->assertStringContainsString("if (\$operation === 'delete' && \$entity->isNew())", $source);
    $this->assertStringContainsString('AccessResult::forbidden()->addCacheableDependency($entity)', $source);

    // Cacheability: every branch that decides for itself must declare its
    // permission dependency, or the result leaks across users — including the
    // fall-through, which is reached only after the admin check.
    $this->assertStringContainsString('cachePerPermissions()', $source);
    $this->assertStringContainsString('default => AccessResult::neutral()->cachePerPermissions()', $source);
    // The admin permission is read from the entity type at runtime, and guarded
    // rather than cast so an entity type without one falls through to the
    // per-operation checks instead of short-circuiting on an empty string.
    $this->assertStringContainsString('$this->entityType->getAdminPermission()', $source);
    $this->assertStringContainsString('is_string($adminPermission)', $source);
    // PHPStan level max needs the untyped $context array documented.
    $this->assertStringContainsString('@phpstan-param array<string, mixed> $context', $source);
  }

  /**
   * The class name defaults to the entity type id plus the handler suffix.
   */
  public function testClassDefaultsFromEntityTypeId(): void {
    $result = $this->generate(['id' => 'my_thing']);
    $this->assertContains('modules/mymod/src/MyThingAccessControlHandler.php', $result->created);
  }

  /**
   * An explicit class input is honoured verbatim.
   */
  public function testExplicitClassIsHonoured(): void {
    $result = $this->generate(['id' => 'my_thing', 'class' => 'CustomGuard']);
    $this->assertContains('modules/mymod/src/CustomGuard.php', $result->created);
  }

  /**
   * Inputs that sanitise to an empty entity type id are rejected, not written.
   */
  public function testEmptyDerivedIdThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->generate(['id' => '***']);
  }

  /**
   * A second run over an existing file skips it (never overwrites).
   */
  public function testRerunSkipsExistingFile(): void {
    $this->generate(['id' => 'example_profile']);
    $second = $this->generate(['id' => 'example_profile']);
    $this->assertContains('modules/mymod/src/ExampleProfileAccessControlHandler.php', $second->skipped);
  }

  /**
   * A dry run reports the planned file without writing it.
   */
  public function testDryRunWritesNothing(): void {
    $result = $this->generate(['id' => 'example_profile'], TRUE);
    $this->assertContains('modules/mymod/src/ExampleProfileAccessControlHandler.php', $result->created);
    $this->assertFileDoesNotExist($this->appRoot . '/modules/mymod/src/ExampleProfileAccessControlHandler.php');
  }

  /**
   * A "*\/" in --label cannot break out of the docblock and inject code.
   *
   * The sanitiser lives on AbstractBlueprint, so this also proves a brand-new
   * blueprint inherits the protection rather than having to re-implement it.
   */
  public function testLabelCannotBreakOutOfDocblock(): void {
    $this->generate([
      'id' => 'evil_type',
      'class' => 'EvilHandler',
      'label' => 'X */ const INJECTED = 1; /*',
    ]);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/src/EvilHandler.php');

    // Tokenise: a docblock break-out would turn "const INJECTED" into a real
    // T_CONST statement. Neutralised, "const" survives only as comment text.
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
    (new AccessHandlerBlueprint())->generate(
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
