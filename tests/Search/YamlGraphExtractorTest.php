<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Search;

use Droost\Engine\Search\Graph\YamlGraphExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Tests services/routing yml graph extraction.
 */
final class YamlGraphExtractorTest extends TestCase {

  /**
   * Services yield pseudo-symbols, service_class edges, and injects edges.
   */
  public function testServices(): void {
    $yaml = <<<'YML'
services:
  fx.worker:
    class: Drupal\fx\Worker
    arguments: ['@database', '@?optional.thing', 'scalar', '%param%']
  Drupal\fx\SelfNamed:
    autowire: true
  fx.classless:
    arguments: ['@state']
YML;
    $result = (new YamlGraphExtractor())->extract($yaml, 'modules/custom/fx/fx.services.yml', 'fx');

    $symbols = array_column($result['symbols'], 'fqcn');
    $this->assertSame(['service:fx.worker', 'service:Drupal\fx\SelfNamed', 'service:fx.classless'], $symbols);
    $this->assertSame('service', $result['symbols'][0]['kind']);
    $this->assertSame('modules/custom/fx/fx.services.yml', $result['symbols'][0]['file']);
    $this->assertGreaterThan(0, $result['symbols'][0]['line'], 'Best-effort line found.');

    $edges = array_map(static fn(array $e): string => $e['src'] . '|' . $e['dst'] . '|' . $e['kind'], $result['edges']);
    $this->assertContains('service:fx.worker|Drupal\fx\Worker|service_class', $edges);
    $this->assertContains('service:Drupal\fx\SelfNamed|Drupal\fx\SelfNamed|service_class', $edges, 'FQCN id provides itself.');
    $this->assertContains('service:fx.worker|service:database|injects', $edges);
    $this->assertContains('service:fx.worker|service:optional.thing|injects', $edges, 'The optional @? prefix is stripped.');
    $this->assertContains('service:fx.classless|service:state|injects', $edges);
    $this->assertNotContains('service:fx.worker|service:scalar|injects', $edges, 'Scalars are not injections.');
  }

  /**
   * Routes yield pseudo-symbols and routes_to for _controller/_form only.
   */
  public function testRouting(): void {
    $yaml = <<<'YML'
route_callbacks:
  - '\Drupal\fx\Routes::dynamic'
fx.page:
  path: '/fx'
  defaults:
    _controller: '\Drupal\fx\Controller\FxController::page'
fx.invokable:
  path: '/fx/i'
  defaults:
    _controller: 'Drupal\fx\Controller\Invokable'
fx.settings:
  path: '/fx/settings'
  defaults:
    _form: 'Drupal\fx\Form\SettingsForm'
fx.entity:
  path: '/fx/{node}'
  defaults:
    _entity_view: 'node.full'
YML;
    $result = (new YamlGraphExtractor())->extract($yaml, 'modules/custom/fx/fx.routing.yml', 'fx');

    $symbols = array_column($result['symbols'], 'fqcn');
    $this->assertSame(['route:fx.page', 'route:fx.invokable', 'route:fx.settings', 'route:fx.entity'], $symbols);
    $this->assertNotContains('route:route_callbacks', $symbols, 'Non-route keys are skipped.');

    $edges = array_map(static fn(array $e): string => $e['src'] . '|' . $e['dst'] . '|' . $e['kind'], $result['edges']);
    $this->assertContains('route:fx.page|Drupal\fx\Controller\FxController::page|routes_to', $edges);
    $this->assertContains('route:fx.invokable|Drupal\fx\Controller\Invokable::__invoke|routes_to', $edges, 'A bare class normalizes to ::__invoke.');
    $this->assertContains('route:fx.settings|Drupal\fx\Form\SettingsForm|routes_to', $edges);
    $this->assertCount(3, $result['edges'], 'The _entity_view route lands a symbol but no edge.');
  }

  /**
   * Malformed and unrecognized inputs yield empty results, never a throw.
   */
  public function testDegenerate(): void {
    $extractor = new YamlGraphExtractor();
    $this->assertSame(['symbols' => [], 'edges' => []], $extractor->extract("\t\tbroken: [", 'x.services.yml', 'x'));
    $this->assertSame(['symbols' => [], 'edges' => []], $extractor->extract('a: 1', 'x.info.yml', 'x'), 'Unrecognized filenames extract nothing.');
    $this->assertSame(['symbols' => [], 'edges' => []], $extractor->extract('no_services_key: 1', 'x.services.yml', 'x'));
  }

}
