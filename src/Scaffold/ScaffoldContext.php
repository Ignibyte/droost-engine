<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold;

/**
 * Immutable inputs for one scaffold run.
 */
final readonly class ScaffoldContext {

  /**
   * Constructs a ScaffoldContext.
   *
   * @param string $appRoot
   *   The Drupal application root.
   * @param string $module
   *   The target module machine name.
   * @param string $modulePath
   *   The target module path relative to the app root.
   * @param array<string, string> $inputs
   *   Resolved blueprint input values.
   * @param bool $dryRun
   *   When TRUE, report planned files but write nothing.
   */
  public function __construct(
    public string $appRoot,
    public string $module,
    public string $modulePath,
    public array $inputs,
    public bool $dryRun,
  ) {}

  /**
   * Returns an input value (trimmed), or a default.
   *
   * @param string $name
   *   The input name.
   * @param string $default
   *   The default when absent/empty.
   *
   * @return string
   *   The value.
   */
  public function input(string $name, string $default = ''): string {
    $value = trim($this->inputs[$name] ?? '');
    return $value === '' ? $default : $value;
  }

}
