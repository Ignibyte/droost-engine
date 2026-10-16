<?php

declare(strict_types=1);

namespace Droost\Engine\Site;

/**
 * What the engine is allowed to know about the site it is running against.
 *
 * The engine answers questions about a project from two very different
 * positions: inside a booted Drupal site, where the installed extensions are a
 * fact, and from a bare checkout, where they are not knowable at all. This is
 * the seam between those, and it exists to keep one specific mistake out of
 * the engine: reporting something as absent when the truth is that nobody
 * asked the site.
 *
 * "Not installed" is a claim about the site, and only a locator that can see
 * the site may make it. An empty installed() from a locator that cannot ask
 * the site means "I could not tell", never "you do not have it".
 *
 * @see \Droost\Engine\Site\UnknownSite
 */
interface ExtensionLocatorInterface {

  /**
   * Every installed extension, for callers that must enumerate.
   *
   * @return list<string>
   *   The machine names, unordered. EMPTY when the site cannot be asked,
   *   which is indistinguishable from a site with nothing installed: never
   *   decide that something is absent from it.
   */
  public function installed(): array;

  /**
   * Locates an extension on disk.
   *
   * @param string $name
   *   The extension machine name.
   *
   * @return string|null
   *   The path relative to the application root, or NULL when the extension is
   *   unknown or unresolvable. An extension can be listed as installed and
   *   still have no directory (removed while still in core.extension).
   */
  public function path(string $name): ?string;

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
