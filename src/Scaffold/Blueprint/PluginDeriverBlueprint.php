<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a plugin deriver: one plugin class, many runtime instances.
 *
 * A deriver is how a single plugin class becomes one plugin PER thing — per
 * entity type, per bundle, per configured item. Menu blocks, field formatters
 * that vary by field type, and "one block per view display" are all derivers.
 *
 * The trap this blueprint exists to remove is that a deriver is only half a
 * pattern: writing the DeriverBase subclass does nothing until the plugin
 * points at it with `deriver:` in its attribute, and a deriver nothing points
 * at is a class that never runs. So this emits BOTH, and the derivative ids
 * they agree on.
 *
 * Inputs: id (the deriver/plugin machine name), class (defaults to the id in
 * PascalCase), label.
 */
final class PluginDeriverBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'plugin-deriver';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A plugin deriver (DeriverBase) PLUS the block plugin that declares it — one class, one plugin instance per entity type. A deriver nothing points at never runs, so both halves are emitted together. --id names the plugin.';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the inputs cannot yield a valid plugin id and PHP class name.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    $id = $this->machineName($context->input('id', 'example_derived'));
    $rawClass = $context->input('class', '');
    $class = $this->className($rawClass !== '' ? $rawClass : $id);
    if ($id === '' || $class === '') {
      throw new \InvalidArgumentException('Could not derive a valid plugin id and class from the inputs. Pass --id (a-z, 0-9, _) and optionally --class (a valid PHP class name).');
    }
    $label = $context->input('label', '');
    if ($label === '') {
      $label = ucwords(str_replace('_', ' ', $id));
    }
    $tokens = [
      '{{module}}' => $context->module,
      '{{class}}' => $class,
      '{{id}}' => $id,
      '{{label}}' => $this->phpString($label),
      '{{label_doc}}' => $this->docText($label),
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/src/Plugin/Derivative/' . $class . 'Deriver.php',
      strtr($this->deriverTemplate(), $tokens),
      $result,
    );
    $this->writeFile(
      $context,
      $context->modulePath . '/src/Plugin/Block/' . $class . 'Block.php',
      strtr($this->blockTemplate(), $tokens),
      $result,
    );
  }

  /**
   * The deriver template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function deriverTemplate(): string {
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\{{module}}\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives one {{label_doc}} block per content entity type.
 *
 * ContainerDeriverInterface (not just DeriverBase) is what gives a deriver
 * dependency injection: create() receives the container, so derivatives can be
 * built from real site state instead of a hardcoded list. That is the whole
 * point — a hardcoded list could have been written as separate plugin classes.
 */
final class {{class}}Deriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * Constructs a {{class}}Deriver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $base_plugin_definition
   *   The plugin definition every derivative starts from.
   *
   * @return array<string, mixed>
   *   The derivative definitions, keyed by derivative id.
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $derivatives = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $entityType) {
      // Content entity types only: a config entity has no canonical listing
      // this block would be about, and deriving one would give the site a
      // block that renders nothing.
      if ($entityType->getGroup() !== 'content') {
        continue;
      }
      $definition = $base_plugin_definition;
      $definition['admin_label'] = $this->t('@label: @entity', [
        '@label' => '{{label}}',
        '@entity' => $entityType->getLabel(),
      ]);
      // Each derivative depends on the module that supplies its entity type,
      // or uninstalling that module leaves a block placement pointing at a
      // plugin that no longer derives. Read-then-write rather than appending
      // straight into the nested key: the base definition is whatever the
      // plugin declared, and assuming its shape is how a scaffold that passes
      // static analysis here starts failing in someone else's module.
      $dependencies = is_array($definition['config_dependencies'] ?? NULL)
        ? $definition['config_dependencies']
        : [];
      $modules = [];
      foreach (is_array($dependencies['module'] ?? NULL) ? $dependencies['module'] : [] as $existing) {
        if (is_string($existing)) {
          $modules[$existing] = TRUE;
        }
      }
      $modules[$entityType->getProvider()] = TRUE;
      $dependencies['module'] = array_keys($modules);
      $definition['config_dependencies'] = $dependencies;
      // The derivative KEY becomes the second half of the plugin id (the
      // part after the colon), which is what a caller places and what config
      // stores. Keying on the entity type id rather than a counter is what
      // keeps that id stable across rebuilds.
      $derivatives[(string) $id] = $definition;
    }
    $this->derivatives = $derivatives;
    return $derivatives;
  }

}
PHP;
  }

  /**
   * The derived block plugin template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function blockTemplate(): string {
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\{{module}}\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the {{label_doc}} block, one instance per content entity type.
 *
 * The `deriver` key is the load-bearing line. Without it this is one ordinary
 * block; with it, the plugin manager asks the deriver for instances and the
 * site sees one per entity type, suffixed ":node", ":user" and so on. A
 * deriver class no plugin names is never called — which is why the scaffold
 * writes both files at once.
 */
#[Block(
  id: '{{id}}',
  admin_label: new TranslatableMarkup('{{label}}'),
  deriver: \Drupal\{{module}}\Plugin\Derivative\{{class}}Deriver::class,
)]
final class {{class}}Block extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function build(): array {
    // getDerivativeId() is the key the deriver used — here the entity type
    // id. Read it rather than parsing the plugin id string: the separator is
    // the plugin system's business, not this class's.
    $entityType = $this->getDerivativeId() ?? '';
    return [
      // The label is escaped for a PHP string literal here, not for a
      // docblock: the two need different quoting.
      '#markup' => $this->t('This is the @type instance of {{label}}.', [
        '@type' => $entityType,
      ]),
      // Derived from site state, so the block must be invalidated when that
      // state changes. Without this the first-built variant is served for
      // every derivative.
      '#cache' => [
        'tags' => ['entity_types'],
      ],
    ];
  }

}
PHP;
  }

}
