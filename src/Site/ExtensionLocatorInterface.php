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
 * That is why isInstalled() is three-valued rather than a bool. "Not
 * installed" hides guidance, prunes results and changes what an agent believes
 * this project can do; it is a claim about the site, and only a locator that
 * can see the site may make it. A caller that collapses NULL into FALSE has
 * turned "I could not tell" into "you do not have it", which is the exact
 * failure this interface is shaped to prevent.
 *
 * @see \Droost\Engine\Site\UnknownSite
 */
interface ExtensionLocatorInterface {

  /**
   * Whether an extension is installed and enabled on this site.
   *
   * @param string $name
   *   The extension machine name.
   *
   * @return bool|null
   *   TRUE or FALSE when the site can be asked; NULL when it cannot. Treat
   *   NULL as "unknown" — never as FALSE.
   */
  public function isInstalled(string $name): ?bool;

  /**
   * Every installed extension, for callers that must enumerate.
   *
   * @return list<string>
   *   The machine names, unordered. EMPTY when the site cannot be asked,
   *   which is indistinguishable from a site with nothing installed — so use
   *   isInstalled() for any decision that turns on absence.
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
   *   still have no directory (removed while still in core.extension), so this
   *   returning NULL is not a contradiction of isInstalled().
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
