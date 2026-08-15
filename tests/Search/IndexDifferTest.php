<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Search;

use Droost\Engine\Search\IndexDiffer;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure manifest-vs-discovery diff behind incremental indexing.
 */
final class IndexDifferTest extends TestCase {

  /**
   * Empty manifest and empty discovery yield an empty delta.
   */
  public function testEmptyBoth(): void {
    $delta = IndexDiffer::diff([], [], ['custom']);
    $this->assertSame([], $delta->added);
    $this->assertSame([], $delta->changed);
    $this->assertSame([], $delta->removed);
    $this->assertSame(0, $delta->unchanged);
    $this->assertTrue($delta->isEmpty());
  }

  /**
   * Unknown discovered paths are added.
   */
  public function testAdds(): void {
    $delta = IndexDiffer::diff([], ['a.php' => 'h1', 'b.php' => 'h2'], ['custom']);
    $this->assertSame(['a.php', 'b.php'], $delta->added);
    $this->assertSame(0, $delta->unchanged);
    $this->assertSame(['a.php', 'b.php'], $delta->toParse());
    $this->assertSame([], $delta->toDrop());
  }

  /**
   * Hash mismatches are changed; matches are unchanged.
   */
  public function testChangedAndUnchanged(): void {
    $manifest = [
      'a.php' => ['hash' => 'h1', 'scope' => 'custom'],
      'b.php' => ['hash' => 'h2', 'scope' => 'custom'],
    ];
    $delta = IndexDiffer::diff($manifest, ['a.php' => 'h1', 'b.php' => 'DIFFERENT'], ['custom']);
    $this->assertSame([], $delta->added);
    $this->assertSame(['b.php'], $delta->changed);
    $this->assertSame([], $delta->removed);
    $this->assertSame(1, $delta->unchanged);
    $this->assertSame(['b.php'], $delta->toParse());
    $this->assertSame(['b.php'], $delta->toDrop());
    $this->assertFalse($delta->isEmpty());
  }

  /**
   * Undiscovered manifest rows are removed ONLY within the requested scopes.
   */
  public function testScopeAwareRemoval(): void {
    $manifest = [
      'modules/custom/x/a.php' => ['hash' => 'h1', 'scope' => 'custom'],
      'modules/contrib/y/b.php' => ['hash' => 'h2', 'scope' => 'contrib'],
    ];
    // A custom-only run does not discover the contrib file — the contrib row
    // must NOT be treated as deleted.
    $delta = IndexDiffer::diff($manifest, [], ['custom']);
    $this->assertSame(['modules/custom/x/a.php'], $delta->removed);

    // A run covering both scopes removes both.
    $both = IndexDiffer::diff($manifest, [], ['custom', 'contrib']);
    $this->assertSame(['modules/custom/x/a.php', 'modules/contrib/y/b.php'], $both->removed);
  }

  /**
   * A mixed delta classifies every path exactly once.
   */
  public function testMixed(): void {
    $manifest = [
      'keep.php' => ['hash' => 'same', 'scope' => 'custom'],
      'edit.php' => ['hash' => 'old', 'scope' => 'custom'],
      'gone.php' => ['hash' => 'x', 'scope' => 'custom'],
    ];
    $discovered = [
      'keep.php' => 'same',
      'edit.php' => 'new',
      'fresh.php' => 'n1',
    ];
    $delta = IndexDiffer::diff($manifest, $discovered, ['custom']);
    $this->assertSame(['fresh.php'], $delta->added);
    $this->assertSame(['edit.php'], $delta->changed);
    $this->assertSame(['gone.php'], $delta->removed);
    $this->assertSame(1, $delta->unchanged);
    $this->assertSame(['fresh.php', 'edit.php'], $delta->toParse());
    $this->assertSame(['edit.php', 'gone.php'], $delta->toDrop());
  }

}
