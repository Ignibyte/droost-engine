<?php

declare(strict_types=1);

namespace Droost\Engine\Wiki;

/**
 * Where the wiki bundle lives on this project.
 *
 * A single method, because that is all the reader needs and a wider port would
 * drag the host's settings object across a boundary that exists to keep it
 * out. The path is asked for per call rather than handed over once: it is
 * resolved from configuration that a request can legitimately change, and a
 * reader holding a stale string would write pages into a directory nobody is
 * reading.
 */
interface BundleLocatorInterface {

  /**
   * The absolute path to the wiki bundle directory.
   *
   * @return string
   *   The resolved path. Not guaranteed to exist — a bundle that has never
   *   been generated is a normal state, not an error.
   */
  public function bundlePath(): string;

}
