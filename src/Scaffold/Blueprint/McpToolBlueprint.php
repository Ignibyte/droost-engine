<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a new MCP #[Tool] plugin (the Droost pattern) plus a kernel test.
 *
 * Droost teaching agents to extend Droost: emits a read-only tool returning the
 * {success, message, data} envelope, with a create() factory and a test that
 * registers and runs it. Inputs: id, class, label, description.
 */
final class McpToolBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'mcp-tool';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A new MCP tool plugin (#[Tool]) following the Droost pattern, with a test.';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the inputs cannot yield a valid tool id and PHP class name.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    $id = $this->machineName($context->input('id', 'example_tool'));
    $rawClass = $context->input('class', '');
    $class = $this->className($rawClass !== '' ? $rawClass : $id);
    if ($id === '' || $class === '') {
      throw new \InvalidArgumentException('Could not derive a valid tool id and class from the inputs. Pass --id (a-z, 0-9, _) and optionally --class (a valid PHP class name).');
    }
    $label = $context->input('label', $class);
    $description = $context->input('description', $label . '. Read-only.');

    // DroostToolBase lives in Drupal\droost\Plugin\Tool. When scaffolding into
    // droost itself the generated class shares that namespace, so importing it
    // would be a redundant (and phpcs-flagged) self-import.
    $useBase = $context->module === 'droost'
      ? ''
      : "use Drupal\\droost\\Plugin\\Tool\\DroostToolBase;\n";
    // The label reaches two sites: PHP string literals (escaped via phpString)
    // and a docblock comment (neutralised via docText so a "*/" in the label
    // cannot terminate the comment and inject top-level code).
    $tokens = [
      '{{use_base}}' => $useBase,
      '{{module}}' => $context->module,
      '{{class}}' => $class,
      '{{id}}' => $id,
      '{{label}}' => $this->phpString($label),
      '{{label_doc}}' => $this->docText($label),
      '{{description}}' => $this->phpString($description),
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/src/Plugin/Tool/' . $class . '.php',
      strtr($this->toolTemplate(), $tokens),
      $result,
    );
    $this->writeFile(
      $context,
      $context->modulePath . '/tests/src/Kernel/Plugin/Tool/' . $class . 'Test.php',
      strtr($this->testTemplate(), $tokens),
      $result,
    );
  }

  /**
   * The #[Tool] plugin class template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function toolTemplate(): string {
    return <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Drupal\{{module}}\Plugin\Tool;

    use Drupal\Core\StringTranslation\TranslatableMarkup;
    {{use_base}}use Drupal\mcp_server\Attribute\Tool;
    use Mcp\Server\ClientGateway;
    use Symfony\Component\DependencyInjection\ContainerInterface;

    /**
     * MCP tool: {{label_doc}}.
     */
    #[Tool(
      id: '{{id}}',
      label: new TranslatableMarkup('{{label}}'),
      description: new TranslatableMarkup('{{description}}'),
      inputSchema: ['type' => 'object'],
      outputSchema: [
        'type' => 'object',
        'properties' => [
          'success' => ['type' => 'boolean', 'description' => 'Whether the call succeeded.'],
          'message' => ['type' => 'string', 'description' => 'Human-readable summary.'],
          'data' => ['description' => 'The result payload.'],
        ],
        'required' => ['success', 'message'],
      ],
      readOnly: TRUE,
      destructive: FALSE,
      idempotent: TRUE,
      openWorld: FALSE,
    )]
    final class {{class}} extends DroostToolBase {

      /**
       * {@inheritdoc}
       *
       * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
       *   The container. Pull any services you need here.
       * @param array<string, mixed> $configuration
       *   The configuration.
       * @param string $plugin_id
       *   The plugin ID.
       * @param mixed $plugin_definition
       *   The plugin definition.
       */
      #[\Override]
      public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
        return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_user'));
      }

      /**
       * {@inheritdoc}
       */
      public function execute(array $arguments, ClientGateway $gateway): mixed {
        // Implement the tool. Return data in the {success, message, data} envelope.
        return $this->succeed('{{label}} ran.', []);
      }

    }
    PHP;
  }

  /**
   * The kernel test template for the generated tool.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function testTemplate(): string {
    return <<<'PHP_WRAP'
    <?php
    
    declare(strict_types=1);
    
    namespace Drupal\Tests\{{module}}\Kernel\Plugin\Tool;
    
    use Drupal\KernelTests\KernelTestBase;
    use Drupal\mcp_server\Plugin\ToolPluginInterface;
    use Mcp\Server\ClientGateway;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

    /**
     * Tests the {{id}} MCP tool.
     */
    #[Group('{{module}}')]
    #[RunTestsInSeparateProcesses]
    final class {{class}}Test extends KernelTestBase {
    
      /**
       * {@inheritdoc}
       *
       * @var array<int, string>
       */
      protected static $modules = ['system', 'user', 'mcp_server', 'droost', '{{module}}'];
    
      /**
       * The tool is discovered and returns the success envelope.
       */
      public function testToolExecutes(): void {
        $manager = $this->container->get('plugin.manager.mcp_server.tool');
        $tool = $manager->createInstance('{{id}}');
        $this->assertInstanceOf(ToolPluginInterface::class, $tool);
        $result = $tool->execute([], $this->createMock(ClientGateway::class));
        $this->assertIsArray($result);
        $this->assertTrue($result['success'] ?? NULL);
      }
    
    }
    PHP_WRAP;
  }

}
