<?php

declare(strict_types=1);

namespace Droost\Engine\Site;

/**
 * The locator for when there is no site to ask.
 *
 * A plain checkout, a CLI run before install, a test fixture: the engine still
 * has work to do, and it does it without pretending to know the site. The core
 * version is '' unless it is known from outside the site.
 */
final readonly class UnknownSite implements ExtensionLocatorInterface {

  /**
   * Constructs an UnknownSite.
   *
   * @param string $coreVersion
   *   A core version to report when it is known from outside the site (a
   *   composer constraint, a CLI flag). Defaults to '' — unknown.
   */
  public function __construct(
    private string $coreVersion = '',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function coreVersion(): string {
    return $this->coreVersion;
  }

}
