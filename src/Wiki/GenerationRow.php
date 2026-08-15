<?php

declare(strict_types=1);

namespace Droost\Engine\Wiki;

/**
 * The outcome of generating (or refusing) one module's wiki page.
 *
 * A value object rendered as one row of the `droost:wiki:generate` report:
 * {module, action, verdict, path}. `action` is the coarse outcome; `verdict`
 * is the freshness verdict on success or a reason token on a refusal/failure.
 */
final readonly class GenerationRow {

  /**
   * Constructs a GenerationRow.
   *
   * @param string $module
   *   The target module machine name.
   * @param string $action
   *   What happened: 'wrote', 'refused', or 'failed'.
   * @param string $verdict
   *   The freshness verdict on success ('fresh'), or the reason token on a
   *   refusal/failure (e.g. 'not-installed', 'no-provider', 'foreign',
   *   'unmanaged', 'compose-error', 'write-error', 'not-fresh').
   * @param string|null $path
   *   The written page path on success, else NULL.
   * @param string|null $message
   *   A human-readable detail for a refusal/failure, else NULL.
   */
  public function __construct(
    public string $module,
    public string $action,
    public string $verdict,
    public ?string $path,
    public ?string $message,
  ) {}

  /**
   * A successful write-and-verify.
   *
   * @param string $module
   *   The module machine name.
   * @param string $path
   *   The written page path.
   *
   * @return self
   *   The row.
   */
  public static function wrote(string $module, string $path): self {
    return new self($module, 'wrote', 'fresh', $path, NULL);
  }

  /**
   * A refusal (a precondition was not met; nothing written).
   *
   * @param string $module
   *   The module machine name.
   * @param string $verdict
   *   The reason token.
   * @param string $message
   *   The human-readable detail.
   *
   * @return self
   *   The row.
   */
  public static function refused(string $module, string $verdict, string $message): self {
    return new self($module, 'refused', $verdict, NULL, $message);
  }

  /**
   * A failure (the attempt ran but did not produce a fresh page).
   *
   * @param string $module
   *   The module machine name.
   * @param string $verdict
   *   The reason token.
   * @param string $message
   *   The human-readable detail.
   *
   * @return self
   *   The row.
   */
  public static function failed(string $module, string $verdict, string $message): self {
    return new self($module, 'failed', $verdict, NULL, $message);
  }

  /**
   * Whether the page was written and verified fresh.
   *
   * @return bool
   *   TRUE when the action succeeded.
   */
  public function ok(): bool {
    return $this->action === 'wrote';
  }

}
