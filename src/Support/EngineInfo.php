<?php

declare(strict_types=1);

namespace Droost\Engine\Support;

/**
 * Reports the engine's own release identity.
 *
 * Consumers pin droost/engine per minor for the whole 0.x line, so hosts that
 * assemble engine objects at runtime (the droost module's factory, the
 * droost_workflow CLI) need a cheap way to record which build they resolved —
 * diagnostics reports and support threads quote it.
 *
 * The constant is the single source of truth and is bumped in the same commit
 * that cuts a release tag.
 */
final class EngineInfo {

  /**
   * The engine release this build reports.
   */
  public const VERSION = '0.1.1';

  /**
   * Returns the engine release string.
   *
   * @return string
   *   The semantic version of this engine build.
   */
  public static function version(): string {
    return self::VERSION;
  }

}
