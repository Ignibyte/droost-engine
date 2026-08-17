<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a runnable functional (BrowserTestBase) test skeleton.
 *
 * The kernel-test blueprint covers the fast tier; this one covers the tier
 * that installs a real site and drives it over HTTP. What it produces is
 * green from the first run — it asserts a page every Drupal site serves
 * regardless of front-page configuration — and it makes the two classic
 * functional failures explicit in the emitted file: a missing
 * $defaultTheme (required, not defaulted), and the environment the tier
 * needs (SIMPLETEST_BASE_URL + a web server), which drupal.org's GitLab CI
 * provisions by default.
 *
 * Inputs: id (names the class), class, label, modules (a comma-separated
 * $modules chain; the target module is appended when omitted), theme (the
 * $defaultTheme; "stark" when omitted).
 */
final class FunctionalTestBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'functional-test';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A runnable BrowserTestBase skeleton in tests/src/Functional. Installs a real site and drives it over HTTP — needs SIMPLETEST_BASE_URL (drupal.org CI provisions it). Pass --modules=a,b,c for the $modules chain and --theme for $defaultTheme (default "stark").';
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
    $class = $rawClass !== '' ? $this->className($rawClass) : $this->className($id);
    if ($id === '' || $class === '') {
      throw new \InvalidArgumentException('Could not derive a valid id and class from the inputs. Pass --id (a-z, 0-9, _) and optionally --class (a valid PHP class name).');
    }
    $class .= 'Test';

    $theme = $this->machineName($context->input('theme', 'stark'));
    if ($theme === '' || preg_match('/^[a-z][a-z0-9_]*$/', $theme) !== 1) {
      throw new \InvalidArgumentException('The --theme option must be a theme machine name (a-z, 0-9, _).');
    }

    $modules = $this->moduleList($context->input('modules', ''), $context->module);
    $tokens = [
      '{{module}}' => $context->module,
      '{{class}}' => $class,
      '{{theme}}' => $theme,
      '{{modules_list}}' => implode(', ', array_map(
        fn (string $name): string => "'" . $this->phpString($name) . "'",
        $modules,
      )),
      '{{label_doc}}' => $this->docText($context->input('label', $id)),
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/tests/src/Functional/' . $class . '.php',
      strtr($this->testTemplate(), $tokens),
      $result,
    );
  }

  /**
   * The functional test template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function testTemplate(): string {
    return <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Drupal\Tests\{{module}}\Functional;

    use Drupal\Tests\BrowserTestBase;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

    /**
     * Functional tests for {{label_doc}}.
     *
     * A functional test INSTALLS a real site (the "testing" profile) and
     * drives it over HTTP — so unlike a kernel test it runs hook_install()
     * and imports config/install, and it costs minutes, not seconds. It
     * needs SIMPLETEST_BASE_URL, SIMPLETEST_DB and a web server serving
     * this codebase (phpunit.xml or the environment); drupal.org's GitLab
     * CI provisions all three by default.
     *
     * $modules here is NOT the kernel chain: BrowserTestBase installs
     * through the real module installer with dependency resolution ON, so
     * listing this module alone pulls everything it depends on — the
     * opposite of a kernel test, where every dependency is listed by hand.
     * The classic failure of this tier is different: $defaultTheme.
     * BrowserTestBase requires it (unset is an error, not a default),
     * because every assertion about the page is an assertion about that
     * theme's markup.
     */
    #[Group('{{module}}')]
    #[RunTestsInSeparateProcesses]
    final class {{class}} extends BrowserTestBase {

      /**
       * {@inheritdoc}
       */
      protected $defaultTheme = '{{theme}}';

      /**
       * {@inheritdoc}
       *
       * @var array<int, string>
       */
      protected static $modules = [{{modules_list}}];

      /**
       * The installed site serves a page with this module enabled.
       *
       * Deliberately generic and green from the start: /user/login exists
       * on every Drupal site regardless of front-page configuration, so
       * this passes as soon as the install (with the $modules chain above)
       * succeeds. The install itself is the real subject — a broken
       * hook_install() or invalid config/install fails before this runs.
       * Replace it with the behaviour you actually care about.
       */
      public function testSiteServesPages(): void {
        $this->drupalGet('user/login');
        $this->assertSession()->statusCodeEquals(200);
        $moduleHandler = $this->container->get('module_handler');
        $this->assertTrue($moduleHandler->moduleExists('{{module}}'));
      }

    }
    PHP;
  }

}
