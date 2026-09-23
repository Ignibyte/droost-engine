<?php

declare(strict_types=1);

namespace Droost\Engine\Site;

use Composer\InstalledVersions;

/**
 * Decides who owns an extension's code, from its path and composer's record.
 *
 * Before this class droost had five answers to the question, and they
 * disagreed. Each one looked for "/contrib/" or "/custom/" in the path, and
 * each fell back differently when neither appeared: one said custom, one
 * contrib, one "other". A site whose installer-paths put contrib at
 * modules/{$name} had every module filed as custom, owed a wiki page and open
 * to scaffolding.
 *
 * Composer knows, so it is asked first. The rules, in order:
 *
 * 1. A path under core/ is core.
 * 2. The directory of the Drupal package composer installed the path into
 *    decides: drupal-core is core, drupal-custom-* is custom, and every other
 *    drupal-* type is contrib. The deepest such directory wins, so a
 *    submodule belongs to its project.
 * 3. Otherwise the first path segment named contrib or custom decides.
 * 4. Otherwise the code was placed by hand, which makes it the project's
 *    own: custom, when composer's record was read. Without that record there
 *    is no telling, and the answer is unknown.
 *
 * A package installed at or above the app root owns nothing. That is the
 * root package of a module's own CI checkout, which would otherwise claim
 * every extension on the site.
 *
 * Nothing here touches the filesystem. Paths are compared as written, so a
 * package composer linked into modules/contrib from elsewhere is still found
 * at its link.
 */
final readonly class ScopeClassifier {

  /**
   * Constructs a ScopeClassifier.
   *
   * @param array<string, string>|null $owners
   *   The directories composer installed a Drupal package into, relative to
   *   the app root, mapped to the package type. NULL when composer's record
   *   could not be read.
   */
  private function __construct(
    private ?array $owners,
  ) {}

  /**
   * The classifier for the running site, from composer's runtime record.
   *
   * @param string $appRoot
   *   The absolute app root (the docroot).
   *
   * @return self
   *   The classifier.
   */
  public static function forAppRoot(string $appRoot): self {
    if (!class_exists(InstalledVersions::class)) {
      return self::withoutComposer();
    }
    return self::fromInstalledData($appRoot, InstalledVersions::getAllRawData());
  }

  /**
   * A classifier built from InstalledVersions::getAllRawData()'s shape.
   *
   * @param string $appRoot
   *   The absolute app root (the docroot).
   * @param array<mixed> $datasets
   *   One entry per vendor directory, each carrying a "versions" map of
   *   package name to its "type" and "install_path".
   *
   * @return self
   *   The classifier.
   */
  public static function fromInstalledData(string $appRoot, array $datasets): self {
    $root = self::normalize($appRoot);
    $owners = [];
    foreach ($datasets as $dataset) {
      $packages = is_array($dataset) ? ($dataset['versions'] ?? NULL) : NULL;
      if (!is_array($packages)) {
        continue;
      }
      foreach ($packages as $package) {
        $type = is_array($package) ? ($package['type'] ?? NULL) : NULL;
        $path = is_array($package) ? ($package['install_path'] ?? NULL) : NULL;
        if (!is_string($type) || !str_starts_with($type, 'drupal-') || !is_string($path) || $path === '') {
          continue;
        }
        $relative = self::below($root, self::normalize($path));
        if ($relative !== NULL) {
          $owners[$relative] = $type;
        }
      }
    }
    return new self($owners);
  }

  /**
   * A classifier with no composer record: paths alone decide.
   *
   * @return self
   *   The classifier.
   */
  public static function withoutComposer(): self {
    return new self(NULL);
  }

  /**
   * Whether composer's record was read.
   *
   * @return bool
   *   FALSE when every answer rests on the path alone.
   */
  public function knowsComposer(): bool {
    return $this->owners !== NULL;
  }

  /**
   * Classifies an extension by its path.
   *
   * @param string $path
   *   The extension's directory relative to the app root, as Drupal's
   *   extension lists report it ("modules/contrib/webform").
   *
   * @return \Droost\Engine\Site\Provenance
   *   Who owns the code.
   */
  public function classify(string $path): Provenance {
    $path = ltrim(self::normalize($path), '/');
    if ($path === 'core' || str_starts_with($path, 'core/')) {
      return Provenance::Core;
    }
    if ($this->owners !== NULL) {
      for ($dir = $path; $dir !== '' && $dir !== '.'; $dir = self::parent($dir)) {
        if (isset($this->owners[$dir])) {
          return self::fromType($this->owners[$dir]);
        }
      }
    }
    foreach (explode('/', $path) as $segment) {
      if ($segment === 'contrib') {
        return Provenance::Contrib;
      }
      if ($segment === 'custom') {
        return Provenance::Custom;
      }
    }
    return $this->owners === NULL ? Provenance::Unknown : Provenance::Custom;
  }

  /**
   * Maps a composer package type onto its provenance.
   *
   * @param string $type
   *   A drupal-* package type.
   *
   * @return \Droost\Engine\Site\Provenance
   *   The provenance.
   */
  private static function fromType(string $type): Provenance {
    return match (TRUE) {
      $type === 'drupal-core' => Provenance::Core,
      str_starts_with($type, 'drupal-custom-') => Provenance::Custom,
      default => Provenance::Contrib,
    };
  }

  /**
   * The parent of a relative directory, '' above the top.
   *
   * @param string $dir
   *   A relative directory.
   *
   * @return string
   *   Its parent.
   */
  private static function parent(string $dir): string {
    $slash = strrpos($dir, '/');
    return $slash === FALSE ? '' : substr($dir, 0, $slash);
  }

  /**
   * A path's position strictly below a root, or NULL when it is not below.
   *
   * @param string $root
   *   A normalized absolute root.
   * @param string $path
   *   A normalized absolute path.
   *
   * @return string|null
   *   The relative path, or NULL for the root itself and anything outside.
   */
  private static function below(string $root, string $path): ?string {
    $prefix = rtrim($root, '/') . '/';
    return str_starts_with($path, $prefix) && strlen($path) > strlen($prefix) ? substr($path, strlen($prefix)) : NULL;
  }

  /**
   * Resolves "." and ".." segments in a path without touching the disk.
   *
   * @param string $path
   *   A path, absolute or relative.
   *
   * @return string
   *   The path with no empty, "." or resolvable ".." segments.
   */
  private static function normalize(string $path): string {
    $path = str_replace('\\', '/', $path);
    $absolute = str_starts_with($path, '/');
    $parts = [];
    foreach (explode('/', $path) as $segment) {
      if ($segment === '' || $segment === '.') {
        continue;
      }
      if ($segment === '..') {
        if ($parts !== [] && end($parts) !== '..') {
          array_pop($parts);
        }
        elseif (!$absolute) {
          $parts[] = '..';
        }
        continue;
      }
      $parts[] = $segment;
    }
    return ($absolute ? '/' : '') . implode('/', $parts);
  }

}
