<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a modern OOP hook implementation (Drupal 11.1+) plus a test.
 *
 * Droost's value-add over DCG, whose `hook` generator only emits a procedural
 * `function <module>_<hook>()` in the .module file. This writes a service-style
 * class under src/Hook/ with a `#[Hook('<name>')]` method (ready for
 * constructor dependency injection), matching guidelines/topics/hooks-oop.md,
 * plus a reflection unit test that the attribute is wired. Green-by-default:
 * phpcs + phpstan-max with an empty baseline.
 *
 * Inputs: hook (the hook name, e.g. "entity_presave"), class.
 */
final class HookBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'hook';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A modern OOP hook implementation (#[Hook] on a src/Hook/ class, DI-ready), with a test — the idiom DCG\'s procedural generator lacks.';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the inputs cannot yield a valid hook name and PHP class.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    // Both inputs are sanitised to safe character sets (a-z0-9_ and a
    // PascalCase identifier), so neither can break out of the string literal,
    // docblock, or attribute they are embedded in — no escaping is needed.
    $hook = $this->machineName($context->input('hook', 'cron'));
    $class = $this->className($context->input('class', '')) ?: $this->className($context->module . '_hooks');
    if ($hook === '' || $class === '') {
      throw new \InvalidArgumentException('Could not derive a valid hook name and class. Pass --hook (e.g. entity_presave) and optionally --class.');
    }
    $method = 'on' . $this->pascalCase($hook);
    $tokens = [
      '{{module}}' => $context->module,
      '{{class}}' => $class,
      '{{hook}}' => $hook,
      '{{method}}' => $method,
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/src/Hook/' . $class . '.php',
      strtr($this->hookTemplate(), $tokens),
      $result,
    );
    $this->writeFile(
      $context,
      $context->modulePath . '/tests/src/Unit/Hook/' . $class . 'Test.php',
      strtr($this->testTemplate(), $tokens),
      $result,
    );
  }

  /**
   * The OOP hook class template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function hookTemplate(): string {
    return <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Drupal\{{module}}\Hook;

    use Drupal\Core\Hook\Attribute\Hook;

    /**
     * Hook implementations for the {{module}} module.
     */
    final class {{class}} {

      /**
       * Implements {{hook}}().
       */
      #[Hook('{{hook}}')]
      public function {{method}}(): void {
        // Implement {{hook}}(). Add the hook's parameters to this method
        // signature, and inject any services via a constructor.
      }

    }
    PHP;
  }

  /**
   * The reflection unit-test template for the generated hook class.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function testTemplate(): string {
    return <<<'PHP_WRAP'
    <?php

    declare(strict_types=1);

    namespace Drupal\Tests\{{module}}\Unit\Hook;

    use Drupal\Core\Hook\Attribute\Hook;
    use Drupal\{{module}}\Hook\{{class}};
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * Tests the {{class}} hook class.
     */
    #[Group('{{module}}')]
    final class {{class}}Test extends TestCase {

      /**
       * The {{method}} method carries the #[Hook('{{hook}}')] attribute.
       */
      public function testHookAttribute(): void {
        $method = new \ReflectionMethod({{class}}::class, '{{method}}');
        $attributes = $method->getAttributes(Hook::class);
        $this->assertNotEmpty($attributes, 'The method declares a #[Hook] attribute.');
        $this->assertContains('{{hook}}', $attributes[0]->getArguments());
      }

    }
    PHP_WRAP;
  }

}
