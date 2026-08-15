<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Accumulates the outcome of an install or uninstall across harnesses.
 *
 * Structured (rather than IO-scraped) so the Drush command renders a uniform
 * summary and tests assert on outcomes directly.
 */
final class InstallResult {

  /**
   * Relative paths written or modified.
   *
   * @var array<int, string>
   */
  public array $written = [];

  /**
   * Relative paths removed (region or whole file) during uninstall.
   *
   * @var array<int, string>
   */
  public array $removed = [];

  /**
   * Human-readable notes about skipped actions.
   *
   * @var array<int, string>
   */
  public array $skipped = [];

  /**
   * Warnings (e.g. a conflicting hand-added entry).
   *
   * @var array<int, string>
   */
  public array $warnings = [];

  /**
   * Records a written/modified path.
   *
   * @param string $path
   *   The relative path.
   */
  public function addWritten(string $path): void {
    $this->written[] = $path;
  }

  /**
   * Records a removed path.
   *
   * @param string $path
   *   The relative path.
   */
  public function addRemoved(string $path): void {
    $this->removed[] = $path;
  }

  /**
   * Records a skipped action.
   *
   * @param string $note
   *   The note.
   */
  public function addSkipped(string $note): void {
    $this->skipped[] = $note;
  }

  /**
   * Records a warning.
   *
   * @param string $warning
   *   The warning.
   */
  public function addWarning(string $warning): void {
    $this->warnings[] = $warning;
  }

  /**
   * Merges another result into this one.
   *
   * @param \Droost\Engine\Harness\InstallResult $other
   *   The result to merge.
   */
  public function merge(InstallResult $other): void {
    $this->written = [...$this->written, ...$other->written];
    $this->removed = [...$this->removed, ...$other->removed];
    $this->skipped = [...$this->skipped, ...$other->skipped];
    $this->warnings = [...$this->warnings, ...$other->warnings];
  }

}
