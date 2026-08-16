<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a CKEditor 5 plugin: the PHP half, honestly labelled.
 *
 * A CKEditor 5 plugin is two things that live in different worlds. The PHP
 * half declares the plugin to Drupal — which toolbar button it provides, which
 * text-format elements it permits, what configuration it exposes. The
 * JavaScript half is the editor behaviour itself, and it is a real CKEditor 5
 * plugin built with webpack against the CKEditor packages.
 *
 * **This blueprint emits the PHP half and a declaration wired for a JS plugin
 * you have not written yet.** That is deliberate. Scaffolding a stub JS
 * plugin would produce a build that cannot run — CKEditor 5 sources are
 * compiled, not dropped in — and a generated file that must be replaced
 * wholesale before anything works is worse than an honest gap.
 *
 * The emitted `.ckeditor5.yml` therefore ships with a **plain-PHP element
 * declaration and no `drupalElementStyles`/JS class**, which is a complete,
 * installable plugin for the very common case where a text format just needs
 * an element allowed. The JS path is documented in the file for when the
 * plugin needs editor behaviour.
 *
 * Inputs: id (the plugin machine name), class (defaults to the id in
 * PascalCase), label, description.
 */
final class Ckeditor5PluginBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'ckeditor5-plugin';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A CKEditor 5 plugin: the PHP class plus its <module>.ckeditor5.yml declaration, configurable and element-declaring. Emits NO JavaScript — CKEditor 5 sources are webpack-compiled, so a stub would be a build that cannot run; the yml documents where the JS goes.';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the inputs cannot yield a valid plugin id and PHP class name.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    $id = $this->machineName($context->input('id', 'example_ckeditor5'));
    $rawClass = $context->input('class', '');
    $class = $this->className($rawClass !== '' ? $rawClass : $id);
    if ($id === '' || $class === '') {
      throw new \InvalidArgumentException('Could not derive a valid plugin id and class from the inputs. Pass --id (a-z, 0-9, _) and optionally --class (a valid PHP class name).');
    }
    $label = $context->input('label', '');
    if ($label === '') {
      $label = ucwords(str_replace('_', ' ', $id));
    }
    // The plugin id CKEditor 5 sees is namespaced by module, which is what
    // keeps two modules' "highlight" plugins apart.
    $pluginId = $context->module . '_' . $id;
    $tokens = [
      '{{module}}' => $context->module,
      '{{class}}' => $class,
      '{{plugin_id}}' => $pluginId,
      '{{label}}' => $this->phpString($label),
      '{{label_doc}}' => $this->docText($label),
      '{{label_yaml}}' => $this->yamlScalar($label),
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/src/Plugin/CKEditor5Plugin/' . $class . '.php',
      strtr($this->pluginTemplate(), $tokens),
      $result,
    );
    $this->writeFile(
      $context,
      $context->modulePath . '/' . $context->module . '.ckeditor5.yml',
      strtr($this->declarationTemplate(), $tokens),
      $result,
    );
  }

  /**
   * Quotes a value as a single-quoted YAML scalar.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   The quoted scalar.
   */
  private function yamlScalar(string $value): string {
    return "'" . str_replace("'", "''", $value) . "'";
  }

  /**
   * The PHP plugin template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function pluginTemplate(): string {
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\{{module}}\Plugin\CKEditor5Plugin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\ckeditor5\Plugin\CKEditor5PluginConfigurableTrait;
use Drupal\ckeditor5\Plugin\CKEditor5PluginDefault;
use Drupal\editor\EditorInterface;

/**
 * The {{label_doc}} CKEditor 5 plugin.
 *
 * CKEditor5PluginDefault covers the common case; the configurable trait adds
 * a settings form on the text-format edit screen. Extend
 * CKEditor5PluginDefinition-aware interfaces only when you need them —
 * implementing CKEditor5PluginElementsSubsetInterface, for instance, means
 * this plugin has to answer which SUBSET of its declared elements is enabled,
 * and getting that wrong silently strips markup on save.
 */
final class {{class}} extends CKEditor5PluginDefault implements PluginFormInterface {

  use CKEditor5PluginConfigurableTrait;

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    return ['enabled_by_default' => TRUE];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['enabled_by_default'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled by default'),
      '#default_value' => $this->configuration['enabled_by_default'] ?? TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    // Nothing to validate for a checkbox. Validate here rather than in submit:
    // a text format saved with invalid plugin settings is accepted by the
    // editor and fails at render time, far from the cause.
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['enabled_by_default'] = (bool) $form_state->getValue('enabled_by_default');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $static_plugin_config
   *   The plugin configuration declared in the *.ckeditor5.yml file.
   * @param \Drupal\editor\EditorInterface $editor
   *   The text editor this configuration is for.
   *
   * @return array<string, mixed>
   *   The dynamic plugin configuration handed to the JavaScript plugin.
   */
  public function getDynamicPluginConfig(array $static_plugin_config, EditorInterface $editor): array {
    // This is how PHP-side settings reach the editor at runtime. It is merged
    // over the static config, so return only what changes — returning the
    // whole block would overwrite keys the yml deliberately set.
    return $static_plugin_config + [
      '{{plugin_id}}' => [
        'enabledByDefault' => (bool) ($this->configuration['enabled_by_default'] ?? TRUE),
      ],
    ];
  }

}
PHP;
  }

  /**
   * The *.ckeditor5.yml declaration template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function declarationTemplate(): string {
    return <<<'YAML'
# CKEditor 5 plugin declarations for this module.
#
# Each top-level key is a plugin id. Drupal reads this file to learn which
# plugins exist, what they contribute to the toolbar, and — critically — which
# HTML elements they permit, which is what the text format's filter allows
# through. An element declared here but not produced by the editor is harmless;
# one PRODUCED but not declared is silently stripped on save.
{{plugin_id}}:
  ckeditor5:
    plugins: []
    # To add editor behaviour, write a real CKEditor 5 plugin (a webpack build
    # against the ckeditor5 packages), declare its library in
    # {{module}}.libraries.yml, and reference the exported class here:
    #
    #   plugins:
    #     - {{module}}.{{class}}
    #
    # This scaffold deliberately emits no JavaScript: CKEditor 5 sources are
    # compiled, not dropped in, so a generated stub would be a build that
    # cannot run.
  drupal:
    label: {{label_yaml}}
    library: {{module}}/{{plugin_id}}
    admin_library: {{module}}/{{plugin_id}}.admin
    toolbar_items: {}
    elements:
      # Declare every element and attribute this plugin may produce. "<em>"
      # allows the tag; "<em class>" allows the attribute too. Without the
      # attribute form, the tag survives and its classes are stripped, which
      # looks like a CSS bug rather than a filter one.
      - <span>
      - <span class>
    conditions:
      # Only offered on formats that already allow these plugins/filters.
      # Omit the key entirely rather than leaving it empty: an empty
      # "conditions" is a condition set nothing can satisfy.
      filter: filter_html
YAML;
  }

}
