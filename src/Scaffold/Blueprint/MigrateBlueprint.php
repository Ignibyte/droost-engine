<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a migrate source or process plugin.
 *
 * Two deliberate decisions, both of which change what a first run gives you.
 *
 * **A source is emitted WITH the migration that uses it.** A migrate source
 * plugin nobody references is inert — `drush migrate:status` will not list it,
 * because migrations are discovered from `migrations/*.yml`, not from plugin
 * classes. Emitting the class alone produces a file that looks finished and
 * does nothing, which is the same trap `plugin-deriver` avoids by emitting the
 * block that names the deriver.
 *
 * **The source extends SourcePluginBase, not SqlBase.** SqlBase is what most
 * examples reach for, and it requires a `key` in the migration's source
 * definition naming a SECOND configured database connection. A scaffolded
 * migration built on it cannot run until someone adds that connection to
 * settings.php, so the generated code would be dead on arrival. The embedded
 * iterator here runs immediately, and swapping in SqlBase later is a smaller
 * step than debugging why nothing worked.
 *
 * The destination is core's `entity:node`. Custom destination plugins are rare
 * and almost always wrong — core ships one per entity type, and a migration
 * writing somewhere other than an entity is usually a script wearing Migrate's
 * clothes.
 *
 * Inputs: plugin-type (source|process, default source), id, class, label.
 */
final class MigrateBlueprint extends AbstractBlueprint {

  /**
   * The plugin kinds this blueprint can emit.
   */
  private const array TYPES = ['source', 'process'];

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'migrate';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A migrate SOURCE plugin plus the migrations/*.yml that runs it (a source no migration references is never discovered), or a migrate PROCESS plugin. Pass --plugin-type=source|process; --id names the plugin.';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the plugin type is unknown or the inputs yield no valid names.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    $type = strtolower($context->input('plugin-type', 'source'));
    if (!in_array($type, self::TYPES, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'The migrate blueprint needs --plugin-type=%s.',
        implode(' or ', self::TYPES),
      ));
    }

    $default = $type === 'source' ? 'example_source' : 'example_process';
    $id = $this->machineName($context->input('id', $default));
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
      '{{label_doc}}' => $this->docText($label),
      '{{migration_id}}' => $context->module . '_' . $id,
    ];

    if ($type === 'process') {
      $this->writeFile(
        $context,
        $context->modulePath . '/src/Plugin/migrate/process/' . $class . '.php',
        strtr($this->processTemplate(), $tokens),
        $result,
      );
      return;
    }

    $this->writeFile(
      $context,
      $context->modulePath . '/src/Plugin/migrate/source/' . $class . '.php',
      strtr($this->sourceTemplate(), $tokens),
      $result,
    );
    $this->writeFile(
      $context,
      $context->modulePath . '/migrations/' . $context->module . '_' . $id . '.yml',
      strtr($this->migrationTemplate(), $tokens),
      $result,
    );
  }

  /**
   * The migrate source template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function sourceTemplate(): string {
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\{{module}}\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Provides the {{label_doc}} migrate source.
 *
 * Extends SourcePluginBase rather than SqlBase deliberately. SqlBase needs a
 * `key` in the migration's source definition naming a second database
 * connection configured in settings.php; until someone adds it, nothing runs.
 * This one iterates data it can reach on its own, so the migration works the
 * moment it is generated.
 *
 * The three methods below are the whole contract, and each one is load-bearing
 * in a way that is easy to get wrong:
 *
 * - fields() documents what a row offers. It is advisory — a typo here does
 *   not fail, it just makes `drush migrate:fields` lie.
 * - getIds() is NOT advisory. It defines the map table's key, which is how
 *   Migrate knows a row has been seen before. Get it wrong and re-running
 *   either duplicates everything or updates the wrong thing, and rollback
 *   cannot find what it created.
 * - initializeIterator() returns the rows. Return an Iterator, not an array,
 *   for anything that could be large: Migrate pulls rows one at a time, and
 *   an array materialises the whole source in memory first.
 */
#[MigrateSource(id: '{{id}}')]
final class {{class}} extends SourcePluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Source property name => human-readable description.
   */
  public function fields(): array {
    return [
      'id' => $this->t('The source row identifier.'),
      'title' => $this->t('The title to import.'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The map key. Its type must match what initializeIterator() actually
   * yields — declaring 'integer' while yielding strings gives a map table
   * that never matches on re-run, so every migration pass re-creates
   * everything.
   *
   * @return array<string, array<string, string>>
   *   Source property name => the map column definition for it.
   */
  public function getIds(): array {
    return [
      'id' => ['type' => 'integer'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return '{{id}}';
  }

  /**
   * {@inheritdoc}
   *
   * Replace the fixture below with the real source — a file read, an API
   * call, a generator. Yielding from a generator keeps memory flat; building
   * an array here does not.
   */
  protected function initializeIterator(): \Iterator {
    $rows = [
      ['id' => 1, 'title' => 'First imported item'],
      ['id' => 2, 'title' => 'Second imported item'],
    ];
    return new \ArrayIterator($rows);
  }

}
PHP;
  }

  /**
   * The migration config template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function migrationTemplate(): string {
    return <<<'YAML'
# A migration is discovered from this directory, not from the plugin class —
# which is why the source plugin alone would never appear in migrate:status.
id: {{migration_id}}
label: '{{label_doc}}'
# Groups let `drush migrate:import --group=` run a related set in order.
migration_group: {{module}}

source:
  plugin: {{id}}

# Each destination property is either copied straight across or run through a
# process pipeline. The map is destination <- source, which reads backwards
# the first few times.
process:
  # A constant, not from the source: every row becomes this bundle.
  type:
    plugin: default_value
    default_value: page
  title: title
  # uid 0 would make every node anonymous; be explicit about ownership.
  uid:
    plugin: default_value
    default_value: 1
  status:
    plugin: default_value
    default_value: 1

destination:
  plugin: 'entity:node'

# migration_dependencies runs other migrations first. Declare them here rather
# than relying on the order you happen to type on the command line.
migration_dependencies:
  required: {  }
  optional: {  }
YAML;
  }

  /**
   * The migrate process-plugin template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function processTemplate(): string {
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\{{module}}\Plugin\migrate\process;

use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Provides the {{label_doc}} migrate process plugin.
 *
 * A process plugin transforms ONE value on its way from source to
 * destination. Use it when core's process plugins (default_value, callback,
 * migration_lookup, sub_process, static_map, skip_on_empty, …) cannot express
 * the transform — reaching for a custom plugin before checking those is the
 * usual reason a migration ends up harder to read than the data.
 *
 * Two things to know about transform():
 *
 * - Returning NULL is a VALUE, not a skip. To drop a row or a property, throw
 *   MigrateSkipRowException or MigrateSkipProcessException — a returned NULL
 *   is written to the destination as null.
 * - $row is the whole row, so a transform may read other source properties.
 *   That is legitimate, and it also makes the plugin order-dependent: it can
 *   only see properties processed before it.
 */
#[MigrateProcess(id: '{{id}}')]
final class {{class}} extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   *
   * @param mixed $value
   *   The incoming value.
   * @param \Drupal\migrate\MigrateExecutableInterface $migrate_executable
   *   The migration executable.
   * @param \Drupal\migrate\Row $row
   *   The row being processed.
   * @param string $destination_property
   *   The destination property being written.
   *
   * @return mixed
   *   The transformed value.
   */
  public function transform(
    mixed $value,
    MigrateExecutableInterface $migrate_executable,
    Row $row,
    string $destination_property,
  ): mixed {
    if (!is_string($value)) {
      return $value;
    }
    return trim($value);
  }

}
PHP;
  }

}
