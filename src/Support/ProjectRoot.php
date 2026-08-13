<?php

declare(strict_types=1);

namespace Droost\Engine\Support;

/**
 * Resolves the project root (the composer root, above the docroot).
 *
 * Harness configs, and the droost_wiki bundle, belong at the project root —
 * not inside the web-accessible docroot. On standard layouts that is the
 * docroot's parent; on layouts where Drupal IS the project root (no parent
 * markers), it falls back to the app root.
 */
final readonly class ProjectRoot {

  /**
   * Constructs a ProjectRoot.
   *
   * @param string $appRoot
   *   The Drupal application root.
   */
  public function __construct(
    private string $appRoot,
  ) {}

  /**
   * Determines the project root.
   *
   * @return string
   *   The project root: the docroot's parent if it holds a project marker,
   *   else the app root.
   */
  public function path(): string {
    $parent = dirname($this->appRoot);
    // Recognise common project-root markers, not just composer.json, so files
    // are not written into the web-accessible docroot on layouts that lack a
    // root composer.json.
    foreach (['composer.json', '.git', '.ddev', 'vendor'] as $marker) {
      if (file_exists($parent . '/' . $marker)) {
        return $parent;
      }
    }
    return $this->appRoot;
  }

  /**
   * Resolves a path against the project root.
   *
   * @param string $path
   *   An absolute path (returned as-is) or a path relative to the project
   *   root.
   *
   * @return string
   *   The resolved path. Not guaranteed to exist.
   */
  public function resolve(string $path): string {
    if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\/\\\\]/', $path) === 1) {
      return $path;
    }
    return $this->path() . '/' . $path;
  }

  /**
   * Whether a resolved path sits inside the web-accessible app root.
   *
   * Used to warn when the wiki bundle (or another project-level artifact)
   * would end up web-accessible.
   *
   * @param string $path
   *   An absolute path.
   *
   * @return bool
   *   TRUE when the path is the app root or beneath it.
   */
  public function isInsideAppRoot(string $path): bool {
    return PathGuard::isWithin($this->appRoot, $path);
  }

}
