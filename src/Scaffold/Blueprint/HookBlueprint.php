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
 * constructor dependency injection), the Drupal 11.1+ way of declaring hooks,
 * plus a reflection unit test that the attribute is wired. Green-by-default:
 * phpcs + phpstan-max with an empty baseline.
 *
 * Inputs: hook (the hook name, e.g. "entity_presave"), class.
 *
 * A hook core invokes for one module at a time may be implemented once per
 * module: ModuleHandler::invoke() throws "Module X should not implement Y
 * more than once" when it finds two. A preprocess hook is one (ThemeManager
 * runs a module's preprocess through invoke()), so a second
 * #[Hook('preprocess_views_view')] class in a module that already has one
 * scaffolded cleanly, passed its test, and failed the first render of every
 * page (F-150). The blueprint refuses that case and names the implementation
 * to add to instead.
 */
final class HookBlueprint extends AbstractBlueprint {

  /**
   * Hooks besides preprocess core invokes for one module at a time.
   *
   * From core's ModuleHandler::invoke() calls whose hook a #[Hook] class can
   * implement: the theme registry's hook_theme, help's hook_help and the
   * mail manager's hook_mail. The .install hooks core also invokes one module
   * at a time live in no class.
   */
  private const ONE_PER_MODULE = ['theme', 'help', 'mail'];

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
    if ($this->oncePerModule($hook)) {
      $existing = $this->existingImplementation($context, $hook);
      if ($existing !== NULL) {
        throw new \InvalidArgumentException(sprintf(
          '%1$s already implements %2$s, in %3$s. Core invokes %2$s for one module at a time, and a module with two implementations fails the first time it runs ("Module %1$s should not implement %2$s more than once"). Add to that implementation instead of scaffolding another.',
          $context->module,
          $hook,
          $existing,
        ));
      }
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
   * Whether core invokes the hook for one module at a time.
   *
   * @param string $hook
   *   The hook name, without the hook_ prefix.
   *
   * @return bool
   *   TRUE for a preprocess hook and the hooks in ONE_PER_MODULE.
   */
  private function oncePerModule(string $hook): bool {
    return $hook === 'preprocess' || str_starts_with($hook, 'preprocess_') || in_array($hook, self::ONE_PER_MODULE, TRUE);
  }

  /**
   * The module's existing implementation of a hook, if it has one.
   *
   * A #[Hook('<hook>')] in any class under the module's src/, or a function
   * <module>_<hook>() in its .module file.
   *
   * @param \Droost\Engine\Scaffold\ScaffoldContext $context
   *   The scaffold context.
   * @param string $hook
   *   The hook name, without the hook_ prefix.
   *
   * @return string|null
   *   The implementing file, relative to the app root, or NULL.
   */
  private function existingImplementation(ScaffoldContext $context, string $hook): ?string {
    $root = $context->appRoot . '/' . $context->modulePath;
    $moduleFile = $root . '/' . $context->module . '.module';
    $function = '/^function\s+' . preg_quote($context->module . '_' . $hook, '/') . '\s*\(/m';
    if (is_file($moduleFile) && preg_match($function, (string) file_get_contents($moduleFile)) === 1) {
      return $context->modulePath . '/' . $context->module . '.module';
    }
    if (!is_dir($root . '/src')) {
      return NULL;
    }
    $attribute = '/#\[\s*(?:\\\\?Drupal\\\\Core\\\\Hook\\\\Attribute\\\\)?Hook\(\s*(?:hook:\s*)?[\'"]' . preg_quote($hook, '/') . '[\'"]/';
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php'
        && preg_match($attribute, (string) file_get_contents($file->getPathname())) === 1) {
        return $context->modulePath . substr($file->getPathname(), strlen($root));
      }
    }
    return NULL;
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
       * The scaffolded method carries the #[Hook] attribute it was built with.
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
