<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold;

/**
 * A scaffold blueprint: composes generated files for a Drupal pattern.
 *
 * Blueprints produce modern, convention-correct, green-by-default code (and a
 * matching test). Template-only blueprints stand alone; later blueprints may
 * also compose Drush's code generators.
 */
interface BlueprintInterface {

  /**
   * The blueprint machine id (e.g. "mcp-tool").
   *
   * @return string
   *   The id.
   */
  public function getId(): string;

  /**
   * A one-line description for the scaffold menu.
   *
   * @return string
   *   The description.
   */
  public function description(): string;

  /**
   * Generates (or, in dry-run, plans) the blueprint's files.
   *
   * @param \Droost\Engine\Scaffold\ScaffoldContext $context
   *   The scaffold context.
   * @param \Droost\Engine\Scaffold\ScaffoldResult $result
   *   The result accumulator.
   *
   * @throws \InvalidArgumentException
   *   When the inputs are invalid for this blueprint.
   * @throws \RuntimeException
   *   When a target file or directory cannot be written.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void;

}
