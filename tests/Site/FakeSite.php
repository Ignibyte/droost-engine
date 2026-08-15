<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Site;

use Droost\Engine\Site\ExtensionLocatorInterface;

/**
 * A site that always knows the answer.
 *
 * The counterpart to UnknownSite, which never does. Between them the two
 * cover the three answers isInstalled() can give, and keeping them as
 * separate doubles is what stops a test from accidentally asserting the
 * "absent" behaviour while actually exercising the "unknown" path.
 *
 * Narrows the return type to bool accordingly — this double is never unsure.
 */
final readonly class FakeSite implements ExtensionLocatorInterface {

  /**
   * Constructs a FakeSite.
   *
   * @param array<string, string> $extensions
   *   Installed extensions mapped to their path relative to the app root. An
   *   empty path means installed but unresolvable on disk.
   * @param string $coreVersion
   *   The core version to report.
   */
  public function __construct(
    private array $extensions = [],
    private string $coreVersion = '11.4.2',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isInstalled(string $name): bool {
    return array_key_exists($name, $this->extensions);
  }

  /**
   * {@inheritdoc}
   */
  public function installed(): array {
    return array_keys($this->extensions);
  }

  /**
   * {@inheritdoc}
   */
  public function path(string $name): ?string {
    $path = $this->extensions[$name] ?? '';
    return $path === '' ? NULL : $path;
  }

  /**
   * {@inheritdoc}
   */
  public function coreVersion(): string {
    return $this->coreVersion;
  }

}
