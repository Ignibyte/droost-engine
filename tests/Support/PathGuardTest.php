<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Support;

use Droost\Engine\Support\PathGuard;
use PHPUnit\Framework\TestCase;

/**
 * Guards PathGuard::contain() — the project-root containment control.
 *
 * The regression under guard: droost_verify resolved a "path" argument by
 * composing ProjectRoot::resolve() (which does not collapse "../" or follow
 * symlinks) with the pure-string isWithin(), so "../../etc" escaped the root
 * because the string still started with "<root>/". contain() realpaths both
 * sides first; these tests prove every escape route is closed.
 */
final class PathGuardTest extends TestCase {

  /**
   * A temp directory targets must stay within.
   */
  private string $base;

  /**
   * The temp root holding $base plus an out-of-base sibling file.
   */
  private string $tmpRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tmpRoot = sys_get_temp_dir() . '/droost_pathguard_' . uniqid('', TRUE);
    $this->base = $this->tmpRoot . '/base';
    mkdir($this->base . '/sub', 0777, TRUE);
    file_put_contents($this->base . '/child.txt', 'x');
    file_put_contents($this->tmpRoot . '/secret.txt', 'x');
    @symlink($this->tmpRoot . '/secret.txt', $this->base . '/link');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $paths = [
      $this->base . '/link',
      $this->base . '/child.txt',
      $this->tmpRoot . '/secret.txt',
      $this->base . '/sub',
      $this->base,
      $this->tmpRoot,
    ];
    foreach ($paths as $path) {
      if (is_link($path) || is_file($path)) {
        @unlink($path);
      }
      elseif (is_dir($path)) {
        @rmdir($path);
      }
    }
    parent::tearDown();
  }

  /**
   * Accepts an in-bounds target and returns its canonical path.
   */
  public function testContainAcceptsInBoundsTargets(): void {
    $child = realpath($this->base . '/child.txt');
    $this->assertSame($child, PathGuard::contain($this->base, $this->base . '/child.txt'));
    // An in-bounds "../" that collapses back inside is still contained.
    $this->assertSame($child, PathGuard::contain($this->base, $this->base . '/sub/../child.txt'));
  }

  /**
   * Rejects every documented way out of the base directory.
   */
  public function testContainRejectsEscapes(): void {
    $this->assertNull(PathGuard::contain($this->base, $this->base . '/../secret.txt'), '"../" escape');
    $this->assertNull(PathGuard::contain($this->base, $this->base . '/../../../../etc'), 'deep "../" escape');
    $this->assertNull(PathGuard::contain($this->base, $this->tmpRoot . '/secret.txt'), 'absolute sibling');
    $this->assertNull(PathGuard::contain($this->base, $this->base . '/link'), 'symlink escaping the base');
    $this->assertNull(PathGuard::contain($this->base, $this->base . '/nope.txt'), 'non-existent target');
  }

}
