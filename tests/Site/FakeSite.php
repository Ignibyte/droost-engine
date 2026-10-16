<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Site;

use Droost\Engine\Site\ExtensionLocatorInterface;

/**
 * A site that always knows its core version.
 *
 * The counterpart to UnknownSite, which never does.
 */
final readonly class FakeSite implements ExtensionLocatorInterface {

  /**
   * Constructs a FakeSite.
   *
   * @param string $coreVersion
   *   The core version to report.
   */
  public function __construct(
    private string $coreVersion = '11.4.2',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function coreVersion(): string {
    return $this->coreVersion;
  }

}
