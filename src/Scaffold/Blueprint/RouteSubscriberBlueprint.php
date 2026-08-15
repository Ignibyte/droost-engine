<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a route subscriber that alters an existing route.
 *
 * Routes are declared in <module>.routing.yml; changing one you do not own —
 * tightening its access, swapping its controller, marking it admin — is code,
 * and the class has to be registered as a tagged service or it never runs.
 * That missing tag is the failure this blueprint exists to prevent, so a run
 * emits the module's services.yml too whenever the file does not exist yet.
 *
 * Inputs: id (names both the class and the service id, so repeated runs in one
 * module never collide), class, label, route (the route NAME to alter, e.g.
 * "system.admin_config"; defaults to "<module>.settings").
 */
final class RouteSubscriberBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'route-subscriber';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A RouteSubscriberBase subclass that alters an existing route, plus the services.yml entry that registers it. Pass --route=<route.name> (default "<module>.settings").';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the inputs cannot yield a valid id and PHP class name.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    $id = $this->machineName($context->input('id', 'example'));
    $rawClass = $context->input('class', '');
    $class = $rawClass !== ''
      ? $this->className($rawClass)
      : $this->className($id . '_route_subscriber');
    if ($id === '' || $class === '') {
      throw new \InvalidArgumentException('Could not derive a valid id and class from the inputs. Pass --id (a-z, 0-9, _) and optionally --class (a valid PHP class name).');
    }
    // A route NAME is dot-separated ("system.admin_config"), so it must NOT be
    // run through machineName() — that would strip the dots and silently
    // target a route that cannot exist. Escape it for a PHP string literal
    // instead, which is the only place it lands.
    $route = $context->input('route', $context->module . '.settings');
    $tokens = [
      '{{module}}' => $context->module,
      '{{class}}' => $class,
      // Derived from the id, not a fixed "<module>.route_subscriber": a second
      // run in the same module would otherwise tell the developer to add a
      // duplicate YAML key, which silently replaces the first subscriber.
      '{{service_id}}' => $context->module . '.' . $id . '_route_subscriber',
      '{{route}}' => $this->phpString($route),
      '{{route_doc}}' => $this->docText($route),
      '{{label_doc}}' => $this->docText($context->input('label', $route)),
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/src/Routing/' . $class . '.php',
      strtr($this->subscriberTemplate(), $tokens),
      $result,
    );
    // Never overwrites: a module that already has a services.yml gets this
    // reported as skipped, and the class docblock carries the entry to merge.
    $this->writeFile(
      $context,
      $context->modulePath . '/' . $context->module . '.services.yml',
      strtr($this->servicesTemplate(), $tokens),
      $result,
    );
  }

  /**
   * The route subscriber class template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function subscriberTemplate(): string {
    return <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Drupal\{{module}}\Routing;

    use Drupal\Core\Routing\RouteSubscriberBase;
    use Symfony\Component\Routing\RouteCollection;

    /**
     * Alters the "{{route_doc}}" route: {{label_doc}}.
     *
     * This class only runs if it is registered as a tagged service. If
     * {{module}}.services.yml was not created alongside this file, add:
     *
     * @code
     * services:
     *   {{service_id}}:
     *     class: Drupal\{{module}}\Routing\{{class}}
     *     tags:
     *       - { name: event_subscriber }
     * @endcode
     *
     * Unlike #[Hook] classes, event subscribers are NOT auto-discovered — a
     * missing tag is silent, and the symptom is simply that nothing happens.
     *
     * Routes are cached, so run drush cr (or rebuild the router) after any
     * change here; editing the class alone changes nothing on a warm site.
     *
     * TIGHTEN, never loosen. Altering someone else's route to REMOVE a
     * requirement hands out access its owner deliberately withheld, and the
     * owner cannot see your change. Adding a NEW requirement key is safe —
     * separate keys are ANDed — but note setRequirement() OVERWRITES the key
     * it is given, so re-setting an existing _permission replaces the owner's
     * value rather than adding to it. Read $route->getRequirement('_permission')
     * first, and keep the result at least as strict as what you found.
     */
    final class {{class}} extends RouteSubscriberBase {

      /**
       * {@inheritdoc}
       */
      protected function alterRoutes(RouteCollection $collection): void {
        // get() returns NULL when the route does not exist — because the
        // providing module is disabled, was renamed, or another subscriber
        // removed it. Never assume it is there.
        $route = $collection->get('{{route}}');
        if ($route === NULL) {
          return;
        }

        // @todo Replace this with the requirement the route should carry.
        // Combining permissions: "a,b" requires BOTH, "a+b" requires EITHER.
        $route->setRequirement('_permission', 'administer site configuration');
      }

    }
    PHP;
  }

  /**
   * The services.yml template registering the subscriber.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function servicesTemplate(): string {
    return <<<'YAML'
    services:
      {{service_id}}:
        class: Drupal\{{module}}\Routing\{{class}}
        tags:
          - { name: event_subscriber }
    YAML;
  }

}
