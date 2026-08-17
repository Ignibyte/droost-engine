<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a runnable kernel test skeleton for any module.
 *
 * The mcp-tool and hook blueprints each emit a test alongside their subject;
 * this one emits a test on its own, so a module scaffolded from any other
 * blueprint (or written by hand) can get a real starting point. What it
 * produces boots the kernel, enables the module, and asserts something true —
 * so it passes on the first run and gives the developer a working harness to
 * add to, rather than a red test or an empty one.
 *
 * Inputs: id (names the class), class, label, modules (a comma-separated
 * $modules chain; the target module is appended when omitted).
 */
final class KernelTestBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'kernel-test';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A runnable KernelTestBase skeleton in tests/src/Kernel. Pass --modules=a,b,c for the $modules chain (kernel tests do NOT auto-enable dependencies); the target module is appended if you leave it out.';
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

    $modules = $this->moduleList($context->input('modules', ''), $context->module);
    $tokens = [
      '{{module}}' => $context->module,
      '{{class}}' => $class,
      '{{modules_list}}' => implode(', ', array_map(
        fn (string $name): string => "'" . $this->phpString($name) . "'",
        $modules,
      )),
      '{{label_doc}}' => $this->docText($context->input('label', $id)),
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/tests/src/Kernel/' . $class . '.php',
      strtr($this->testTemplate(), $tokens),
      $result,
    );
  }

  /**
   * The kernel test template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function testTemplate(): string {
    return <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Drupal\Tests\{{module}}\Kernel;

    use Drupal\KernelTests\KernelTestBase;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

    /**
     * Kernel tests for {{label_doc}}.
     *
     * $modules is enabled in the order given and does NOT pull in
     * dependencies — every module this test needs, including the providers of
     * any base field TYPE your entities use, has to be listed explicitly or
     * the kernel throws at boot.
     *
     * "Enabled" is not "installed": a kernel test wires up services and hooks
     * but never runs hook_install() and never imports config/install. Ask for
     * what this test actually needs in setUp():
     *
     * @code
     * protected function setUp(): void {
     *   parent::setUp();
     *   $this->installEntitySchema('user');
     *   $this->installConfig(['system']);
     * }
     * @endcode
     *
     * $strictConfigSchema defaults to TRUE here, so any config saved during
     * the test is validated against its schema — a mismatch throws on save.
     * That is a feature: it means a passing kernel test also proves the
     * config shape is valid.
     */
    #[Group('{{module}}')]
    #[RunTestsInSeparateProcesses]
    final class {{class}} extends KernelTestBase {

      /**
       * {@inheritdoc}
       *
       * @var array<int, string>
       */
      protected static $modules = [{{modules_list}}];

      /**
       * The kernel boots with this module enabled.
       *
       * A deliberately generic first assertion: it passes as soon as the
       * $modules chain above is correct, so the scaffolded test is green from
       * the start. The real work is the boot itself — an unavailable or
       * misordered module throws before this line runs. Replace it with the
       * behaviour you actually care about.
       */
      public function testModuleIsEnabled(): void {
        $moduleHandler = $this->container->get('module_handler');
        $this->assertTrue($moduleHandler->moduleExists('{{module}}'));
      }

    }
    PHP;
  }

}
