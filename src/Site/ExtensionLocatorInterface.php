<?php

declare(strict_types=1);

namespace Droost\Engine\Site;

/**
 * What the engine is allowed to know about the site it is running against.
 *
 * The engine runs both inside a booted Drupal site and against a bare
 * checkout. All it asks the site is the core version it stamps content with;
 * a locator that cannot ask the site answers '' and the content is
 * unversioned, never guessed.
 *
 * @see \Droost\Engine\Site\UnknownSite
 */
interface ExtensionLocatorInterface {

  /**
   * The running Drupal core version.
   *
   * The full version rather than the major, because callers need both and
   * only one of them can be derived from the other: content is stamped with
   * the precise version an agent is working against, while per-major variants
   * resolve on the leading segment.
   *
   * @return string
   *   The version ("11.4.2"), or '' when it cannot be determined — which
   *   falls back to unversioned content rather than guessing a major.
   */
  public function coreVersion(): string;

}
