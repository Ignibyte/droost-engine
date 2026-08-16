<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Search;

use Droost\Engine\Search\FileManifestInterface;
use Droost\Engine\Search\Storage\SqliteFileManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SQLite file manifest.
 *
 * These assert the CONTRACT the Drupal-backed manifest already implements, not
 * merely that SQLite accepts the statements. The two stores have to agree on
 * behaviour — an incremental index that treats "changed" differently in one of
 * them silently re-indexes forever, or worse, never.
 */
#[CoversClass(SqliteFileManifest::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class SqliteFileManifestTest extends TestCase {

  /**
   * The manifest under test.
   */
  private SqliteFileManifest $manifest;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->manifest = SqliteFileManifest::open(':memory:');
  }

  /**
   * It satisfies the port.
   */
  public function testImplementsThePort(): void {
    $this->assertInstanceOf(FileManifestInterface::class, $this->manifest);
  }

  /**
   * Reading before anything is indexed is empty, not an error.
   */
  public function testReadsEmptyBeforeTheSchemaExists(): void {
    $this->assertSame([], $this->manifest->all());
  }

  /**
   * A full rebuild replaces everything that was there.
   */
  public function testReplaceAllReplaces(): void {
    $this->manifest->replaceAll([
      $this->row('a.php', 'h1'),
      $this->row('b.php', 'h2'),
    ], 100);
    $this->assertSame(['a.php', 'b.php'], $this->paths());

    $this->manifest->replaceAll([$this->row('c.php', 'h3')], 200);
    $all = $this->manifest->all();
    $this->assertSame(['c.php'], $this->paths());
    $this->assertSame(['hash' => 'h3', 'scope' => 'module'], $all['c.php']);
  }

  /**
   * An incremental run upserts changed files and drops removed ones.
   */
  public function testApplyUpsertsAndRemoves(): void {
    $this->manifest->replaceAll([
      $this->row('a.php', 'h1'),
      $this->row('b.php', 'h2'),
      $this->row('c.php', 'h3'),
    ], 100);

    $this->manifest->apply([$this->row('b.php', 'CHANGED')], ['a.php'], 200);

    $all = $this->manifest->all();
    // Sorted: all() is a map keyed by path and neither store promises an
    // order, so asserting one would pin an implementation detail.
    $this->assertSame(['b.php', 'c.php'], $this->paths());
    // The upsert must REPLACE, not duplicate: "file" is the primary key in the
    // Drupal store, and a second row here would make the hash comparison
    // order-dependent.
    $this->assertSame('CHANGED', $all['b.php']['hash']);
    $this->assertSame('h3', $all['c.php']['hash']);
  }

  /**
   * An empty incremental run changes nothing.
   */
  public function testEmptyApplyChangesNothing(): void {
    $this->manifest->replaceAll([$this->row('a.php', 'h1')], 100);
    $this->manifest->apply([], [], 200);
    $this->assertSame(['a.php'], $this->paths());
  }

  /**
   * Batching survives a set larger than one chunk and SQLite's IN limit.
   */
  public function testHandlesMoreRowsThanOneBatch(): void {
    $rows = [];
    for ($i = 0; $i < 1200; $i++) {
      $rows[] = $this->row(sprintf('file%04d.php', $i), 'h' . $i);
    }
    $this->manifest->replaceAll($rows, 100);
    $this->assertCount(1200, $this->manifest->all());

    // Removing more paths than the chunk size exercises the same ceiling on
    // the delete side, which is where SQLite's parameter limit actually bites.
    $this->manifest->apply([], array_column($rows, 'file'), 200);
    $this->assertSame([], $this->manifest->all());
  }

  /**
   * Ensuring the schema twice is harmless.
   */
  public function testEnsureSchemaIsIdempotent(): void {
    $this->manifest->ensureSchema();
    $this->manifest->ensureSchema();
    $this->assertSame([], $this->manifest->all());
  }

  /**
   * The manifest's paths, sorted.
   *
   * @return array<int, string>
   *   The paths.
   */
  private function paths(): array {
    $paths = array_keys($this->manifest->all());
    sort($paths);
    return $paths;
  }

  /**
   * Builds a manifest row.
   *
   * @param string $file
   *   The path.
   * @param string $hash
   *   The content hash.
   *
   * @return array{file: string, hash: string, scope: string, type: string}
   *   The row.
   */
  private function row(string $file, string $hash): array {
    return ['file' => $file, 'hash' => $hash, 'scope' => 'module', 'type' => 'php'];
  }

}
