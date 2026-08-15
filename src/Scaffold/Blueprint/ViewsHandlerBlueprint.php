<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a Views field/filter/sort handler plugin plus its data-alter.
 *
 * The hooks half of "all the plugins" — authoring a custom Views handler is
 * code, not config (the droost_views composer cannot reach it). Each run emits
 * a modern attribute-based handler class AND its own `views_data_alter` hook
 * class registering it on a caller-named table; because attribute hooks allow
 * many implementations per module, repeated runs coexist (distinct class names,
 * never-overwrite), so there is no shared-file merge. Template-only (no DCG
 * generator exists for Views handlers).
 */
final class ViewsHandlerBlueprint extends AbstractBlueprint {

  /**
   * The handler variants: subdir, attribute, and base class.
   *
   * @var array<string, array{subdir: string, attribute: string, base: string}>
   */
  private const array VARIANTS = [
    'field' => ['subdir' => 'field', 'attribute' => 'ViewsField', 'base' => 'FieldPluginBase'],
    'filter' => ['subdir' => 'filter', 'attribute' => 'ViewsFilter', 'base' => 'FilterPluginBase'],
    'sort' => ['subdir' => 'sort', 'attribute' => 'ViewsSort', 'base' => 'SortPluginBase'],
  ];

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'views-handler';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A Views field/filter/sort handler plugin plus its hook_views_data_alter registration. Pass --plugin-type=field|filter|sort and --views-table.';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the plugin type is not field/filter/sort or the id/class is invalid.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    $variantKey = strtolower($context->input('plugin-type', 'field'));
    $variant = self::VARIANTS[$variantKey] ?? NULL;
    if ($variant === NULL) {
      throw new \InvalidArgumentException('The views-handler blueprint needs --plugin-type=field, filter, or sort.');
    }
    $id = $this->machineName($context->input('id', 'example_views_' . $variantKey));
    $rawClass = $context->input('class', '');
    $class = $this->className($rawClass !== '' ? $rawClass : $id);
    if ($id === '' || $class === '') {
      throw new \InvalidArgumentException('Could not derive a valid handler id and class from the inputs. Pass --id (a-z, 0-9, _) and optionally --class.');
    }
    $viewsTable = $this->machineName($context->input('views-table', 'node_field_data'));
    if ($viewsTable === '') {
      $viewsTable = 'node_field_data';
    }
    $tokens = [
      '{{module}}' => $context->module,
      '{{class}}' => $class,
      '{{id}}' => $id,
      '{{label_doc}}' => $this->docText($context->input('label', $class)),
      '{{views_table}}' => $viewsTable,
      '{{attribute}}' => $variant['attribute'],
      '{{base}}' => $variant['base'],
      '{{section}}' => $variantKey,
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/src/Plugin/views/' . $variant['subdir'] . '/' . $class . '.php',
      strtr($this->handlerTemplate($variantKey), $tokens),
      $result,
    );
    $this->writeFile(
      $context,
      $context->modulePath . '/src/Hook/' . $class . 'ViewsData.php',
      strtr($this->dataAlterTemplate(), $tokens),
      $result,
    );
  }

  /**
   * The handler class template for a variant.
   *
   * @param string $variant
   *   The variant: field, filter, or sort.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function handlerTemplate(string $variant): string {
    return $variant === 'field' ? $this->fieldTemplate() : $this->filterSortTemplate();
  }

  /**
   * The Views field handler template.
   *
   * @return string
   *   The template.
   */
  private function fieldTemplate(): string {
    return <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Drupal\{{module}}\Plugin\views\field;

    use Drupal\views\Attribute\{{attribute}};
    use Drupal\views\Plugin\views\field\{{base}};
    use Drupal\views\ResultRow;

    /**
     * Computed Views field handler: {{label_doc}}.
     *
     * Registered on "{{views_table}}" by {{class}}ViewsData. Computed
     * by default: query() adds nothing, render() returns the value. If it maps
     * to a real column, call parent::query() and ensureMyTable().
     */
    #[{{attribute}}('{{id}}')]
    final class {{class}} extends {{base}} {

      /**
       * {@inheritdoc}
       */
      public function query(): void {
        // A computed field adds nothing to the query.
      }

      /**
       * {@inheritdoc}
       */
      public function render(ResultRow $values): string {
        // @todo Compute the value from $values (sanitize any untrusted output with
        // $this->sanitizeValue()); this placeholder renders empty.
        return '';
      }

    }
    PHP;
  }

  /**
   * The Views filter/sort handler template (standard behavior, ready to alter).
   *
   * Emitted empty like core's Standard handler: registered on a field, it
   * applies the standard filter/sort. The developer overrides query() (and
   * operators()/defineOptions() for filters) to customize.
   *
   * @return string
   *   The template.
   */
  private function filterSortTemplate(): string {
    return <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Drupal\{{module}}\Plugin\views\{{section}};

    use Drupal\views\Attribute\{{attribute}};
    use Drupal\views\Plugin\views\{{section}}\{{base}};

    /**
     * Views {{section}} handler: {{label_doc}}.
     *
     * Registered by {{class}}ViewsData on "{{views_table}}"; applies the
     * standard {{section}} on the field. Override query() to customize.
     */
    #[{{attribute}}('{{id}}')]
    final class {{class}} extends {{base}} {

    }
    PHP;
  }

  /**
   * The paired hook_views_data_alter registration template.
   *
   * @return string
   *   The template.
   */
  private function dataAlterTemplate(): string {
    return <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Drupal\{{module}}\Hook;

    use Drupal\Core\Hook\Attribute\Hook;
    use Drupal\Core\StringTranslation\StringTranslationTrait;

    /**
     * Registers the {{id}} Views {{section}} handler on "{{views_table}}".
     *
     * Its own attribute-hook class, so scaffolding more handlers never collides
     * with this one.
     */
    final class {{class}}ViewsData {

      use StringTranslationTrait;

      /**
       * Implements hook_views_data_alter().
       *
       * @param array<string, mixed> $data
       *   The Views data structure to alter.
       */
      #[Hook('views_data_alter')]
      public function viewsDataAlter(array &$data): void {
        $table = is_array($data['{{views_table}}'] ?? NULL) ? $data['{{views_table}}'] : [];
        $table['{{id}}'] = [
          'title' => $this->t('{{label_doc}}'),
          'help' => $this->t('The {{id}} Views {{section}} handler.'),
          '{{section}}' => [
            'id' => '{{id}}',
          ],
        ];
        $data['{{views_table}}'] = $table;
      }

    }
    PHP;
  }

}
