<?php

declare(strict_types=1);

namespace Droost\Engine\Support;

/**
 * Filesystem path-containment helpers.
 *
 * Used to keep file reads inside an intended base directory even when the
 * target is reached via a symlink — realpath() resolves the link, and the
 * resolved path is then checked against the resolved base.
 */
final class PathGuard {

  /**
   * Whether a resolved path is the base directory or sits beneath it.
   *
   * @param string $base
   *   An absolute, resolved base directory.
   * @param string $path
   *   An absolute, resolved path.
   *
   * @return bool
   *   TRUE if $path is contained within $base.
   */
  public static function isWithin(string $base, string $path): bool {
    return $path === $base || str_starts_with($path, rtrim($base, '/') . '/');
  }

  /**
   * Realpath-resolves a target and returns it only if contained within $base.
   *
   * Unlike isWithin() — a pure string compare that trusts already-resolved
   * inputs — this canonicalises both sides with realpath() first, so "../"
   * segments and symlinks in $target cannot escape $base. Use it whenever
   * $target derives from an untrusted argument that has NOT been resolved
   * (e.g. a "path" tool argument): isWithin() alone would accept
   * "<base>/../../etc" because that string starts with "<base>/".
   *
   * @param string $base
   *   The directory the target must live inside (need not be pre-resolved).
   * @param string $target
   *   The candidate path (need not be pre-resolved; may contain "..").
   *
   * @return string|null
   *   The canonical absolute target when it exists and is contained within
   *   $base, or NULL otherwise (non-existent, unreadable, or escaping).
   */
  public static function contain(string $base, string $target): ?string {
    $real_base = realpath($base);
    $real_target = realpath($target);
    if ($real_base === FALSE || $real_target === FALSE) {
      return NULL;
    }
    return self::isWithin($real_base, $real_target) ? $real_target : NULL;
  }

  /**
   * Resolves a child path and returns it only if contained within a base.
   *
   * @param string $dir
   *   The directory the file must live inside.
   * @param string $file
   *   The child file, relative to $dir.
   * @param array<int, string> $extra_bases
   *   Additional resolved base directories the file may live under instead.
   *   Composer path-repo and drupal.org CI layouts materialize a module as
   *   per-file symlinks into the real checkout; passing that checkout root
   *   (e.g. the resolved home of the module's .info.yml) keeps legitimate
   *   files readable while foreign symlink targets stay rejected.
   *
   * @return string|null
   *   The resolved absolute path if it exists and is contained within $dir
   *   or one of $extra_bases (after following symlinks), or NULL otherwise.
   */
  public static function resolve(string $dir, string $file, array $extra_bases = []): ?string {
    $base = realpath($dir);
    $real = realpath($dir . '/' . $file);
    if ($base === FALSE || $real === FALSE) {
      return NULL;
    }
    foreach ([$base, ...$extra_bases] as $candidate) {
      if (self::isWithin($candidate, $real)) {
        return $real;
      }
    }
    return NULL;
  }

}
