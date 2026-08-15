<?php

declare(strict_types=1);

namespace Droost\Engine\Site;

/**
 * The locator for when there is no site to ask.
 *
 * A plain checkout, a CLI run before install, a test fixture: the engine still
 * has work to do, and it does it without pretending to know what is enabled.
 * Every answer here is the honest "cannot tell", which callers are contracted
 * to treat as "assume it applies" rather than "assume it is absent" — so a
 * run without a site shows everything, and only a run WITH a site narrows to
 * what that site actually has.
 *
 * The opposite default would be worse than useless: guidance silently missing
 * from a checkout looks exactly like guidance that does not exist.
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
  public function isInstalled(string $name): ?bool {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function installed(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function path(string $name): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function coreVersion(): string {
    return $this->coreVersion;
  }

}
