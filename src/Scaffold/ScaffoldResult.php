<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold;

/**
 * Accumulates the outcome of a scaffold run.
 */
final class ScaffoldResult {

  /**
   * Project-relative paths created.
   *
   * @var array<int, string>
   */
  public array $created = [];

  /**
   * Project-relative paths skipped (already exist).
   *
   * @var array<int, string>
   */
  public array $skipped = [];

  /**
   * Records a created path.
   *
   * @param string $path
   *   The relative path.
   */
  public function addCreated(string $path): void {
    $this->created[] = $path;
  }

  /**
   * Records a skipped path.
   *
   * @param string $path
   *   The relative path.
   */
  public function addSkipped(string $path): void {
    $this->skipped[] = $path;
  }

}
