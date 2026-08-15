<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a typed configuration schema for a simple settings object.
 *
 * Emits `config/schema/<module>.<name>.schema.yml` defining a
 * `type: config_object` mapping (the shape of a `<module>.<name>` settings
 * object) plus a matching `config/install/<module>.<name>.yml` default, so the
 * settings are installable and validate against their own schema. A DEDICATED
 * schema file (not the module's main `<module>.schema.yml`) keeps the write
 * additive — Drupal loads every `config/schema/*.yml`, and the blueprint never
 * overwrites an existing file.
 *
 * Config schema is YAML, not PHP, so there is nothing for the PHP gates to
 * flag; correctness is the schema validating under core's strict
 * ConfigSchemaChecker, which a settings save (or module install) exercises.
 *
 * Inputs: id (the settings-object name after the module prefix; default
 * "settings"), label (the config object's human label), description.
 */
final class ConfigSchemaBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'config-schema';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A typed config schema (config/schema/<module>.<name>.schema.yml) for a simple settings object plus its config/install default — the green-by-default way to make module settings translatable, validatable, and export-safe. --id names the settings object (default "settings").';
  }

  /**
   * {@inheritdoc}
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    // The suffix is sanitised to a-z0-9_ and the module name is already a
    // machine name, so the config object key `<module>.<suffix>` is always a
    // safe, unquoted YAML key; the label is inserted as a quoted scalar.
    $suffix = $this->machineName($context->input('id', ''));
    if ($suffix === '') {
      // Guard on '' (not ?:) so a valid one-char name like "0" survives.
      $suffix = 'settings';
    }
    $configName = $context->module . '.' . $suffix;
    $label = $context->input('label', '');
    if ($label === '') {
      $label = ucwords(str_replace('_', ' ', $context->module . ' ' . $suffix));
    }
    $tokens = [
      '{{config_name}}' => $configName,
      '{{label}}' => $this->yamlScalar($label),
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/config/schema/' . $configName . '.schema.yml',
      strtr($this->schemaTemplate(), $tokens),
      $result,
    );
    // The install default uses only fixed keys, so it carries no tokens.
    $this->writeFile(
      $context,
      $context->modulePath . '/config/install/' . $configName . '.yml',
      $this->installTemplate(),
      $result,
    );
  }

  /**
   * Escapes a value for use as a single-quoted YAML scalar.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   A quoted YAML scalar (single quotes, with embedded quotes doubled).
   */
  private function yamlScalar(string $value): string {
    return "'" . str_replace("'", "''", $value) . "'";
  }

  /**
   * The config-schema template.
   *
   * A representative `config_object` exercising the common typed-config element
   * kinds (label, boolean, integer, a sequence of strings, and a nested
   * mapping) — every type a core primitive, so the shape validates under strict
   * config-schema checking.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function schemaTemplate(): string {
    return <<<'YAML'
    {{config_name}}:
      type: config_object
      label: {{label}}
      mapping:
        label:
          type: label
          label: 'Label'
        enabled:
          type: boolean
          label: 'Enabled'
        items_per_page:
          type: integer
          label: 'Items per page'
        tags:
          type: sequence
          label: 'Tags'
          sequence:
            type: string
            label: 'Tag'
        advanced:
          type: mapping
          label: 'Advanced'
          mapping:
            timeout:
              type: integer
              label: 'Timeout in seconds'
    YAML;
  }

  /**
   * The config/install default matching the schema.
   *
   * @return string
   *   The default settings YAML (every key present so module install validates
   *   against the schema under strict checking).
   */
  private function installTemplate(): string {
    return <<<'YAML'
    label: 'Example settings'
    enabled: true
    items_per_page: 10
    tags:
      - example
    advanced:
      timeout: 30
    YAML;
  }

}
