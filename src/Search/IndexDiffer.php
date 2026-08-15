<?php

declare(strict_types=1);

namespace Droost\Engine\Search;

/**
 * Pure diff of the stored file manifest against a fresh discovery pass.
 *
 * The decision logic of incremental indexing, kept free of IO so it is
 * unit-testable against plain maps (the buildArgv/parse discipline). Removal
 * is SCOPE-AWARE: a run that indexes only `custom` must not treat contrib
 * manifest rows as deleted just because discovery did not visit them — a
 * manifest row is removed only when its recorded scope was requested this run
 * and the path was still not discovered.
 */
final class IndexDiffer {

  /**
   * Diffs the manifest against the discovered files.
   *
   * @param array<string, array{hash: string, scope: string}> $manifest
   *   The stored manifest rows, keyed by path.
   * @param array<string, string> $discovered
   *   The freshly discovered files, path => content hash.
   * @param array<int, string> $scopes
   *   The scopes requested for this run.
   *
   * @return \Droost\Engine\Search\IndexDelta
   *   The delta.
   */
  public static function diff(array $manifest, array $discovered, array $scopes): IndexDelta {
    $added = [];
    $changed = [];
    $unchanged = 0;
    foreach ($discovered as $path => $hash) {
      $known = $manifest[$path] ?? NULL;
      if ($known === NULL) {
        $added[] = $path;
      }
      elseif ($known['hash'] !== $hash) {
        $changed[] = $path;
      }
      else {
        $unchanged++;
      }
    }
    $removed = [];
    foreach ($manifest as $path => $row) {
      if (!isset($discovered[$path]) && in_array($row['scope'], $scopes, TRUE)) {
        $removed[] = $path;
      }
    }
    return new IndexDelta($added, $changed, $removed, $unchanged);
  }

}
