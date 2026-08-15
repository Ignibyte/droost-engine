<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Scaffold;

use Droost\Engine\Scaffold\Blueprint\RouteSubscriberBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the route-subscriber blueprint.
 */
#[CoversClass(RouteSubscriberBlueprint::class)]
final class RouteSubscriberBlueprintTest extends TestCase {

  /**
   * The temporary app root.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appRoot = sys_get_temp_dir() . '/droost_rs_' . uniqid();
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
   * A fresh module gets both the subscriber and the services.yml to run it.
   */
  public function testEmitsSubscriberAndServices(): void {
    $result = $this->generate(['id' => 'example', 'route' => 'droost_examples.demo']);

    $class = 'modules/mymod/src/Routing/ExampleRouteSubscriber.php';
    $services = 'modules/mymod/mymod.services.yml';
    $this->assertContains($class, $result->created);
    $this->assertContains($services, $result->created);

    $source = (string) file_get_contents($this->appRoot . '/' . $class);
    $this->assertStringContainsString('declare(strict_types=1);', $source);
    $this->assertStringContainsString('namespace Drupal\mymod\Routing;', $source);
    $this->assertStringContainsString('final class ExampleRouteSubscriber extends RouteSubscriberBase', $source);
    $this->assertStringContainsString('protected function alterRoutes(RouteCollection $collection): void', $source);
    // The null guard is the point of the template: a route may be absent
    // because its module is disabled, and get() then returns NULL.
    $this->assertStringContainsString("\$route = \$collection->get('droost_examples.demo');", $source);
    $this->assertStringContainsString('if ($route === NULL) {', $source);
    $this->assertStringContainsString("\$route->setRequirement('_permission'", $source);

    // The permission conjunction is the easiest thing to get backwards, and
    // it ships into every scaffolded module: comma is AND, plus is OR.
    $this->assertStringContainsString('"a,b" requires BOTH, "a+b" requires EITHER', $source);
    // setRequirement() overwrites, so a careless "tighten" can widen access.
    $this->assertStringContainsString('setRequirement() OVERWRITES', $source);

    $yaml = (string) file_get_contents($this->appRoot . '/' . $services);
    // The service id carries the id, so two subscribers in one module do not
    // collide on a single YAML key.
    $this->assertStringContainsString('mymod.example_route_subscriber:', $yaml);
    $this->assertStringContainsString('class: Drupal\mymod\Routing\ExampleRouteSubscriber', $yaml);
    // Without this tag the subscriber is never invoked — the failure this
    // blueprint exists to prevent.
    $this->assertStringContainsString('{ name: event_subscriber }', $yaml);
    // The docblock's merge snippet must name the SAME service id as the
    // emitted YAML, or a hand-merge silently registers nothing.
    $this->assertStringContainsString('mymod.example_route_subscriber:', $source);
  }

  /**
   * Two runs in one module produce distinct service ids.
   */
  public function testServiceIdsDoNotCollideAcrossRuns(): void {
    $this->generate(['id' => 'first']);
    $this->generate(['id' => 'second']);

    $first = (string) file_get_contents($this->appRoot . '/modules/mymod/src/Routing/FirstRouteSubscriber.php');
    $second = (string) file_get_contents($this->appRoot . '/modules/mymod/src/Routing/SecondRouteSubscriber.php');
    $this->assertStringContainsString('mymod.first_route_subscriber:', $first);
    $this->assertStringContainsString('mymod.second_route_subscriber:', $second);
  }

  /**
   * An existing services.yml is skipped, not overwritten.
   */
  public function testExistingServicesFileIsSkippedNotOverwritten(): void {
    $servicesPath = $this->appRoot . '/modules/mymod/mymod.services.yml';
    mkdir(dirname($servicesPath), 0777, TRUE);
    $original = "services:\n  mymod.pre_existing:\n    class: Drupal\\mymod\\Thing\n";
    file_put_contents($servicesPath, $original);

    $result = $this->generate(['id' => 'example']);

    $this->assertContains('modules/mymod/src/Routing/ExampleRouteSubscriber.php', $result->created);
    $this->assertContains('modules/mymod/mymod.services.yml', $result->skipped);
    $this->assertSame($original, (string) file_get_contents($servicesPath), 'The existing services.yml is byte-identical.');
  }

  /**
   * The route name is used verbatim — dots and all.
   *
   * Route names are dot-separated, so the value must NOT pass through
   * machineName(), which would strip the dots and target a route that cannot
   * exist.
   */
  public function testRouteNameKeepsItsDots(): void {
    $this->generate(['id' => 'example', 'route' => 'system.admin_config']);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/src/Routing/ExampleRouteSubscriber.php');
    $this->assertStringContainsString("\$collection->get('system.admin_config')", $source);
  }

  /**
   * With no route given, the module's own settings route is the target.
   */
  public function testRouteDefaultsToModuleSettings(): void {
    $this->generate(['id' => 'example']);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/src/Routing/ExampleRouteSubscriber.php');
    $this->assertStringContainsString("\$collection->get('mymod.settings')", $source);
  }

  /**
   * An explicit class input is honoured verbatim.
   */
  public function testExplicitClassIsHonoured(): void {
    $result = $this->generate(['id' => 'example', 'class' => 'CustomRoutes']);
    $this->assertContains('modules/mymod/src/Routing/CustomRoutes.php', $result->created);
  }

  /**
   * Inputs that sanitise to an empty id are rejected, not written.
   */
  public function testEmptyDerivedIdThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->generate(['id' => '***']);
  }

  /**
   * A second run over existing files skips them (never overwrites).
   */
  public function testRerunSkipsExistingFiles(): void {
    $this->generate(['id' => 'example']);
    $second = $this->generate(['id' => 'example']);
    $this->assertContains('modules/mymod/src/Routing/ExampleRouteSubscriber.php', $second->skipped);
    $this->assertContains('modules/mymod/mymod.services.yml', $second->skipped);
  }

  /**
   * A dry run plans both files and writes neither.
   */
  public function testDryRunPlansBothAndWritesNothing(): void {
    $result = $this->generate(['id' => 'example'], TRUE);
    $this->assertContains('modules/mymod/src/Routing/ExampleRouteSubscriber.php', $result->created);
    $this->assertContains('modules/mymod/mymod.services.yml', $result->created);
    $this->assertFileDoesNotExist($this->appRoot . '/modules/mymod/src/Routing/ExampleRouteSubscriber.php');
    $this->assertFileDoesNotExist($this->appRoot . '/modules/mymod/mymod.services.yml');
  }

  /**
   * A "*\/" in --label cannot break out of the docblock and inject code.
   */
  public function testLabelCannotBreakOutOfDocblock(): void {
    $this->generate([
      'id' => 'evil',
      'class' => 'EvilRoutes',
      'label' => 'X */ const INJECTED = 1; /*',
    ]);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/src/Routing/EvilRoutes.php');

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
   * A quote in the route name cannot break out of the PHP string literal.
   */
  public function testRouteNameCannotBreakOutOfTheStringLiteral(): void {
    $this->generate([
      'id' => 'sneaky',
      'class' => 'SneakyRoutes',
      'route' => "x'); system('id'); //",
    ]);
    $source = (string) file_get_contents($this->appRoot . '/modules/mymod/src/Routing/SneakyRoutes.php');

    // Tokenise rather than string-match: the definitive check is that the
    // injected call never becomes a real function-call token. It survives
    // only as escaped text inside the route-name literal.
    $calls = 0;
    foreach (token_get_all($source) as $token) {
      if (is_array($token) && $token[0] === T_STRING && $token[1] === 'system') {
        $calls++;
      }
    }
    $this->assertSame(0, $calls, 'A quote in the route name must not close the literal and inject a call.');
    $this->assertStringContainsString("\\'", $source, 'The quote is escaped inside the literal.');
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
    (new RouteSubscriberBlueprint())->generate(
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
