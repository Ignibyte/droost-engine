<?php

declare(strict_types=1);

namespace Droost\Engine\Search;

/**
 * Finds indexable files by walking a directory, with no site to ask.
 *
 * The Drupal discovery asks the extension lists what exists and where, which
 * is both more accurate and unavailable in a bare checkout. This walks the
 * tree instead and infers the owning extension from the path — the one place
 * a repo-only index is weaker than a site-backed one, and worth saying out
 * loud rather than pretending the two are equivalent.
 *
 * Extension attribution looks for the directory containing an `*.info.yml`,
 * which is how a Drupal extension identifies itself on disk. Files outside any
 * such directory get an empty module, exactly as wiki pages do in the Drupal
 * discovery — and the code graph already skips unattributed symbols when
 * summarising module boundaries rather than bucketing them under "".
 */
final class RepoSourceDiscovery {

  /**
   * Directory names never descended into.
   *
   * "modules" and "themes" are NOT skipped here, unlike the Drupal discovery:
   * there, each extension is scanned from its own root, so descending would
   * double-count nested ones. Here the walk starts at the repository root and
   * those directories are where the code lives.
   */
  private const array SKIP_DIRS = ['vendor', 'node_modules', '.git', 'fixtures'];

  /**
   * Maximum file size to index, in bytes (skips generated/minified blobs).
   */
  private const int MAX_FILE_BYTES = 2097152;

  /**
   * Constructs a RepoSourceDiscovery.
   *
   * @param string $root
   *   The directory to index, absolute.
   * @param bool $includeTests
   *   Whether to descend into "tests" directories. Off by default, matching
   *   the Drupal discovery — test fixtures inflate a graph with classes
   *   nothing ships.
   */
  public function __construct(
    private readonly string $root,
    private readonly bool $includeTests = FALSE,
  ) {}

  /**
   * Returns the indexable files under the root.
   *
   * @return array<int, array{path: string, module: string, type: string, scope: string, root: string}>
   *   Each: the path relative to the root, the inferred extension name (empty
   *   when the file sits outside any extension), the type (php|doc|yaml|twig),
   *   a scope of "repo", and the root marker "app" for manifest compatibility.
   */
  public function files(): array {
    if (!is_dir($this->root)) {
      return [];
    }
    $skip = self::SKIP_DIRS;
    if (!$this->includeTests) {
      $skip[] = 'tests';
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
        static fn (\SplFileInfo $f): bool => !$f->isDir() || !in_array($f->getFilename(), $skip, TRUE),
      ),
    );

    $files = [];
    $modules = [];
    foreach ($iterator as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }
      $type = self::typeOf($file->getFilename());
      if ($type === NULL) {
        continue;
      }
      try {
        if ($file->getSize() > self::MAX_FILE_BYTES) {
          continue;
        }
      }
      catch (\RuntimeException) {
        // Unstat-able (a broken symlink, say); skip rather than abort the run.
        continue;
      }
      // Strip exactly the leading root (substr, not str_replace, which would
      // also strip a later occurrence of the same string).
      $relative = ltrim(substr($file->getPathname(), strlen($this->root)), '/');
      $files[] = [
        'path' => $relative,
        'module' => $this->moduleFor($file->getPath(), $modules),
        'type' => $type,
        'scope' => 'repo',
        'root' => 'app',
      ];
    }
    // Deterministic order, so two runs over an unchanged tree produce an
    // identical index rather than one that merely contains the same rows.
    usort($files, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);
    return $files;
  }

  /**
   * Infers the extension owning a directory, walking up to the root.
   *
   * @param string $directory
   *   The absolute directory holding the file.
   * @param array<string, string> $cache
   *   Memo of directory to extension name (by reference); a deep tree asks
   *   the same directories thousands of times.
   *
   * @return string
   *   The extension machine name, or '' when the file is outside one.
   */
  private function moduleFor(string $directory, array &$cache): string {
    if (isset($cache[$directory])) {
      return $cache[$directory];
    }
    $current = $directory;
    $walked = [];
    while ($current !== '' && str_starts_with($current, $this->root)) {
      if (isset($cache[$current])) {
        $found = $cache[$current];
        foreach ($walked as $seen) {
          $cache[$seen] = $found;
        }
        return $found;
      }
      $walked[] = $current;
      $matches = glob($current . '/*.info.yml') ?: [];
      if ($matches !== []) {
        $name = basename($matches[0], '.info.yml');
        foreach ($walked as $seen) {
          $cache[$seen] = $name;
        }
        return $name;
      }
      $parent = dirname($current);
      if ($parent === $current) {
        break;
      }
      $current = $parent;
    }
    foreach ($walked as $seen) {
      $cache[$seen] = '';
    }
    return '';
  }

  /**
   * Classifies a filename, or NULL when it is not indexable.
   *
   * Deliberately identical to the Drupal discovery's rules: the two must agree
   * on what "indexed" means, or the same repository yields different corpora
   * depending on which one ran.
   *
   * @param string $filename
   *   The base filename.
   *
   * @return string|null
   *   php|doc|yaml|twig, or NULL.
   */
  private static function typeOf(string $filename): ?string {
    if (str_ends_with($filename, '.api.php')) {
      return 'doc';
    }
    if (str_ends_with($filename, '.services.yml') || str_ends_with($filename, '.routing.yml')) {
      return 'yaml';
    }
    if (str_ends_with($filename, '.twig')) {
      return 'twig';
    }
    foreach (['.php', '.module', '.inc', '.theme', '.install', '.profile'] as $extension) {
      if (str_ends_with($filename, $extension)) {
        return 'php';
      }
    }
    if (str_ends_with($filename, '.md')) {
      return 'doc';
    }
    return NULL;
  }

}
