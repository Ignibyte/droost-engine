<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds a Single Directory Component (core SDC) in a module.
 *
 * Hand-rolled rather than composed from DCG, whose SDC generator targets themes
 * and is fragile. Writes a `components/<name>/` directory with a schema-typed
 * `<name>.component.yml` (props + a slot, modelled on core's components) and a
 * matching Twig template, plus a README. The component is immediately
 * discoverable as `<module>:<name>` via the SDC plugin manager.
 *
 * SDCs ship no PHP, so there is nothing for the PHP gates to flag; correctness
 * is the component metadata validating against core's SDC schema, which a fresh
 * `drush cr` + render exercises.
 *
 * Inputs: id (component machine name), label (human name), description.
 */
final class SdcBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'sdc';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'A core Single Directory Component (component.yml + Twig) in a module\'s components/ dir, schema-typed and render-ready — the display substrate for Canvas/SDC work.';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the inputs cannot yield a valid component machine name.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    // The machine name is sanitised to a-z0-9_ and the human name/description
    // are inserted as YAML scalar values, so neither can break out of the
    // structure they sit in.
    $machine = $this->machineName($context->input('id', '') ?: $context->input('label', 'example_component'));
    if ($machine === '') {
      throw new \InvalidArgumentException('Could not derive a valid component machine name. Pass --id (e.g. promo_card) and/or --label.');
    }
    $name = $context->input('label', '') ?: ucwords(str_replace('_', ' ', $machine));
    $description = $context->input('description', 'An example single-directory component.');
    // "Elements" is reserved by Canvas's requirements checker; default to a
    // neutral library bucket.
    $group = $context->input('group', '') ?: 'Content';
    $tokens = [
      '{{module}}' => $context->module,
      '{{machine}}' => $machine,
      '{{name}}' => $this->yamlScalar($name),
      '{{description}}' => $this->yamlScalar($description),
      '{{group}}' => $this->yamlScalar($group),
      '{{example}}' => $this->yamlScalar($name . ' heading'),
    ];
    $dir = $context->modulePath . '/components/' . $machine;
    $this->writeFile($context, $dir . '/' . $machine . '.component.yml', strtr($this->metadataTemplate(), $tokens), $result);
    // The Twig template uses only fixed prop/slot names, so it carries no
    // tokens and is written verbatim (its {{ }} are Twig, not placeholders).
    $this->writeFile($context, $dir . '/' . $machine . '.twig', $this->twigTemplate(), $result);
    $this->writeFile($context, $dir . '/README.md', strtr($this->readmeTemplate(), $tokens), $result);
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
   * The component.yml template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function metadataTemplate(): string {
    return <<<'YAML'
    # The $schema line gives IDEs autocomplete for the metadata format.
    $schema: https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json
    name: {{name}}
    description: {{description}}
    # One of: experimental, stable, deprecated, obsolete.
    status: experimental
    # The library bucket page builders (Canvas) file this component under.
    # "Elements" is reserved by Canvas — pick anything else.
    group: {{group}}
    props:
      type: object
      properties:
        attributes:
          type: Drupal\Core\Template\Attribute
          title: Attributes
          description: Wrapper HTML attributes.
        heading:
          type: string
          title: Heading
          description: Optional heading rendered above the slot.
          # Canvas uses examples[0] as the default value in the editor, and
          # requires an example on every required prop.
          examples:
            - {{example}}
    slots:
      content:
        title: Content
        description: The main content of the component.
    YAML;
  }

  /**
   * The Twig template (fixed prop/slot names; no placeholders).
   *
   * @return string
   *   The Twig source.
   */
  private function twigTemplate(): string {
    return <<<'TWIG'
    {#
      Single-directory component template.
      Props: attributes, heading. Slot: content.
    #}
    <div{{ attributes }}>
      {% if heading %}
        <h2>{{ heading }}</h2>
      {% endif %}
      <div class="component__content">
        {% block content %}{% endblock %}
      </div>
    </div>
    TWIG;
  }

  /**
   * The README template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function readmeTemplate(): string {
    return <<<'MARKDOWN'
    # {{name}}

    A single-directory component, discoverable as `{{module}}:{{machine}}`.

    ## Props

    - `attributes` — wrapper HTML attributes.
    - `heading` *(string)* — optional heading rendered above the slot.

    ## Slots

    - `content` — the main content of the component.

    ## Rendering

    From a render array:

    ```php
    $build = [
      '#type' => 'component',
      '#component' => '{{module}}:{{machine}}',
      '#props' => ['heading' => 'Hello'],
      '#slots' => ['content' => ['#markup' => 'World']],
    ];
    ```

    From Twig:

    ```twig
    {% embed '{{module}}:{{machine}}' with { heading: 'Hello' } %}
      {% block content %}World{% endblock %}
    {% endembed %}
    ```
    MARKDOWN;
  }

}
