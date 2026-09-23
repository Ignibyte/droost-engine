<?php

declare(strict_types=1);

namespace Droost\Engine\Site;

/**
 * Who owns an extension's code.
 *
 * Core and contrib arrive through composer and are replaced by the next
 * install, so nothing written into them survives and their documentation is
 * read rather than demanded. Custom is the project's own code. Unknown is the
 * honest answer when composer could not be asked and the path names no
 * owner, which on a composer-built site does not happen.
 *
 * @see \Droost\Engine\Site\ScopeClassifier
 */
enum Provenance: string {

  case Core = 'core';
  case Contrib = 'contrib';
  case Custom = 'custom';
  case Unknown = 'unknown';

  /**
   * Whether composer owns the code, so a write into it is lost.
   *
   * @return bool
   *   TRUE for core and contrib.
   */
  public function isManaged(): bool {
    return $this === self::Core || $this === self::Contrib;
  }

  /**
   * The three-way scope droost stores and filters on.
   *
   * Unknown is held to the custom bar: searched by default, owed a wiki
   * page, writable. The opposite mistake, filing the project's own code as
   * contrib, drops it from coverage where nothing reports the gap.
   *
   * @return string
   *   'core', 'contrib' or 'custom'.
   */
  public function scope(): string {
    return $this === self::Unknown ? self::Custom->value : $this->value;
  }

  /**
   * The search index's scope, where every non-core theme is one bucket.
   *
   * @param bool $isTheme
   *   Whether the extension is a theme.
   *
   * @return string
   *   'core', 'themes', 'contrib' or 'custom'.
   */
  public function indexScope(bool $isTheme): string {
    if ($this === self::Core) {
      return self::Core->value;
    }
    return $isTheme ? 'themes' : $this->scope();
  }

}
