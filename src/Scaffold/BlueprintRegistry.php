<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold;

/**
 * Holds the available scaffold blueprints, keyed by id.
 */
final class BlueprintRegistry {

  /**
   * Blueprints keyed by id.
   *
   * @var array<string, \Droost\Engine\Scaffold\BlueprintInterface>
   */
  private array $blueprints = [];

  /**
   * Constructs a BlueprintRegistry.
   *
   * @param \Droost\Engine\Scaffold\BlueprintInterface ...$blueprints
   *   The available blueprints.
   */
  public function __construct(BlueprintInterface ...$blueprints) {
    foreach ($blueprints as $blueprint) {
      $this->blueprints[$blueprint->getId()] = $blueprint;
    }
  }

  /**
   * Returns a blueprint by id, or NULL.
   *
   * @param string $id
   *   The blueprint id.
   *
   * @return \Droost\Engine\Scaffold\BlueprintInterface|null
   *   The blueprint.
   */
  public function get(string $id): ?BlueprintInterface {
    return $this->blueprints[$id] ?? NULL;
  }

  /**
   * Returns all blueprints keyed by id.
   *
   * @return array<string, \Droost\Engine\Scaffold\BlueprintInterface>
   *   The blueprints.
   */
  public function all(): array {
    return $this->blueprints;
  }

}
