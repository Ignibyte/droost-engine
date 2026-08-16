<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a Drupal recipe: `<project>/recipes/<name>/recipe.yml`.
 *
 * A recipe is the packaging format for "a site configured this way" — modules
 * to install, other recipes to apply first, config to import and act on. It is
 * the only blueprint here whose output belongs OUTSIDE the docroot: Drupal
 * looks for recipes at the project root, a sibling of `web/`, so this uses
 * writeProjectFile() and the created path is reported as `../recipes/…`.
 *
 * `type:` is deliberately omitted from the generated file rather than
 * defaulted. The two values that matter carry real consequences — `Site` marks
 * the recipe installer-selectable and asserts screenshot metadata this
 * blueprint cannot supply, and `'Drupal CMS'` marks it an add-on to that
 * starter. A recipe with no type is an ordinary applicable recipe, which is
 * the only one of the three that is true for every generated file.
 *
 * Inputs: id (the recipe directory and machine name; default the module),
 * label, description, modules (comma-separated, listed under `install`),
 * recipes (comma-separated, applied first).
 */
final class RecipeBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'recipe';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A Drupal recipe at <project>/recipes/<name>/recipe.yml — modules to install, recipes to apply first, and a config/actions stub. Written ABOVE the docroot, where Drupal looks for recipes. --id names it (default: the module), --modules and --recipes seed the lists.';
  }

  /**
   * {@inheritdoc}
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    $name = $this->machineName($context->input('id', ''));
    if ($name === '') {
      $name = $context->module;
    }
    $label = $context->input('label', '');
    if ($label === '') {
      $label = ucwords(str_replace('_', ' ', $name));
    }
    $description = $context->input('description', '');
    if ($description === '') {
      $description = sprintf('The %s recipe.', $label);
    }

    $tokens = [
      '{{name}}' => $this->yamlScalar($label),
      '{{description}}' => $this->yamlScalar($description),
      '{{recipes}}' => $this->yamlList($context->input('recipes', ''), 'recipes'),
      '{{install}}' => $this->yamlList($context->input('modules', ''), 'install'),
    ];
    $this->writeProjectFile(
      $context,
      'recipes/' . $name . '/recipe.yml',
      strtr($this->template(), $tokens),
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
   * Renders a comma-separated input as a YAML block sequence.
   *
   * An EMPTY list is emitted commented-out rather than as `install: []`. An
   * empty sequence is valid YAML and valid recipe config, so Drupal would
   * accept it silently — and a recipe that installs nothing because a key was
   * left empty looks identical to one that was meant to.
   *
   * @param string $raw
   *   The comma-separated input.
   * @param string $key
   *   The recipe key the list belongs to.
   *
   * @return string
   *   The rendered YAML block.
   */
  private function yamlList(string $raw, string $key): string {
    $items = [];
    foreach (explode(',', $raw) as $item) {
      $item = $this->machineName(trim($item));
      if ($item !== '') {
        $items[] = $item;
      }
    }
    if ($items === []) {
      return sprintf("# %s:\n#   - example_module", $key);
    }
    $lines = [$key . ':'];
    foreach ($items as $item) {
      $lines[] = '  - ' . $item;
    }
    return implode("\n", $lines);
  }

  /**
   * The recipe.yml template.
   *
   * @return string
   *   The template.
   */
  private function template(): string {
    return <<<'YAML'
# A Drupal recipe: modules to install, recipes to apply first, and config to
# import or act on. Apply it with the Recipe API, pointing at THIS directory.
#
# "type:" is intentionally absent. Add it only when you mean it:
#   type: 'Site'        — installer-selectable site template. Asserts
#                         screenshot metadata this scaffold cannot supply.
#   type: 'Drupal CMS'  — an add-on to the Drupal CMS starter.
# With no type, this is an ordinary recipe that can be applied to any site.
name: {{name}}
description: {{description}}

# Recipes applied BEFORE this one. Order matters: each is applied in turn.
{{recipes}}

# Modules (and themes) to install.
{{install}}

# Configuration this recipe brings.
#
# "strict: false" lets the recipe apply to a site whose existing config
# differs from what it expects. Leave it out (strict defaults to true) when
# the recipe must refuse to apply rather than silently reconcile.
config:
  strict: false
  # import:
  #   my_module: '*'
  # actions:
  #   system.site:
  #     simpleConfigUpdate:
  #       slogan: 'Set by this recipe'
YAML;
  }

}
