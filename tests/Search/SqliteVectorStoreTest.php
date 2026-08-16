<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Search;

use Droost\Engine\Search\VectorStore\SqliteVectorStore;
use Droost\Engine\Search\VectorStore\VectorStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SQLite vector store.
 *
 * The behaviours under test are the ones that decide whether a search result
 * is trustworthy: refusing a wrong-width query instead of ranking noise,
 * breaking ties the same way every store does, and collapsing duplicate keys
 * rather than aborting a rebuild.
 */
#[CoversClass(SqliteVectorStore::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class SqliteVectorStoreTest extends TestCase {

  /**
   * The store under test.
   */
  private SqliteVectorStore $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->store = SqliteVectorStore::open(':memory:');
  }

  /**
   * It satisfies the port.
   */
  public function testImplementsThePort(): void {
    $this->assertInstanceOf(VectorStoreInterface::class, $this->store);
    $this->assertTrue($this->store->isAvailable());
  }

  /**
   * Reads before anything is stored answer empty, never throw.
   */
  public function testReadsAreSafeBeforeTheSchemaExists(): void {
    $this->assertSame(0, $this->store->count());
    $this->assertSame(0, $this->store->dimension());
    $this->assertSame([], $this->store->search([1.0, 0.0]));
    // Deletes on an absent table are no-ops, not errors.
    $this->store->delete();
    $this->store->deleteByFile('a.php');
    $this->assertSame(0, $this->store->count());
  }

  /**
   * Nearest neighbours come back ranked by cosine similarity.
   */
  public function testSearchRanksByCosine(): void {
    $this->store->upsert('code', 'exact', [1.0, 0.0, 0.0]);
    $this->store->upsert('code', 'near', [0.9, 0.1, 0.0]);
    $this->store->upsert('code', 'far', [0.0, 0.0, 1.0]);

    $hits = $this->store->search([1.0, 0.0, 0.0], 3);
    $this->assertSame(['exact', 'near', 'far'], array_column($hits, 'ref'));
    $this->assertEqualsWithDelta(1.0, $hits[0]['score'], 0.0001);
    $this->assertEqualsWithDelta(0.0, $hits[2]['score'], 0.0001);
  }

  /**
   * A query of the wrong width returns nothing rather than noise.
   */
  public function testWrongDimensionReturnsNothing(): void {
    $this->store->upsert('code', 'a', [1.0, 0.0, 0.0]);
    $this->assertSame(3, $this->store->dimension());
    // Cosine scores every mismatched pair 0.0, so without this guard the
    // caller gets a full page of hits that are all pure noise.
    $this->assertSame([], $this->store->search([1.0, 0.0]));
  }

  /**
   * Ties break on (corpus, ref) so results are reproducible.
   */
  public function testTiesBreakDeterministically(): void {
    foreach (['zulu', 'alpha', 'mike'] as $ref) {
      $this->store->upsert('code', $ref, [1.0, 0.0]);
    }
    $hits = $this->store->search([1.0, 0.0], 3);
    $this->assertSame(['alpha', 'mike', 'zulu'], array_column($hits, 'ref'));
  }

  /**
   * The corpus filter narrows the search.
   */
  public function testSearchFiltersByCorpus(): void {
    $this->store->upsert('code', 'a', [1.0, 0.0]);
    $this->store->upsert('docs', 'b', [1.0, 0.0]);

    $this->assertSame(['a'], array_column($this->store->search([1.0, 0.0], 10, 'code'), 'ref'));
    $this->assertCount(2, $this->store->search([1.0, 0.0], 10));
  }

  /**
   * Upserting the same key replaces rather than duplicating.
   */
  public function testUpsertReplaces(): void {
    $this->store->upsert('code', 'a', [1.0, 0.0], ['v' => 1]);
    $this->store->upsert('code', 'a', [0.0, 1.0], ['v' => 2]);

    $this->assertSame(1, $this->store->count());
    $hits = $this->store->search([0.0, 1.0], 1);
    $this->assertSame(['v' => 2], $hits[0]['meta']);
  }

  /**
   * A rebuild collapses duplicate keys instead of aborting.
   */
  public function testReplaceDedupesByCorpusAndRef(): void {
    $this->store->replace([
      ['corpus' => 'code', 'ref' => 'dupe', 'vector' => [1.0, 0.0], 'meta' => ['n' => 1], 'file' => 'a.php'],
      // Same key, different file — two global functions of one name. Left
      // alone this violates the primary key and takes the whole rebuild down.
      ['corpus' => 'code', 'ref' => 'dupe', 'vector' => [0.0, 1.0], 'meta' => ['n' => 2], 'file' => 'b.php'],
    ], 2);

    $this->assertSame(1, $this->store->count());
    $hits = $this->store->search([0.0, 1.0], 1);
    $this->assertSame(['n' => 2], $hits[0]['meta'], 'last write wins');
  }

  /**
   * A rebuild replaces everything that was there.
   */
  public function testReplaceClearsFirst(): void {
    $this->store->upsert('code', 'old', [1.0, 0.0]);
    $this->store->replace([
      ['corpus' => 'code', 'ref' => 'new', 'vector' => [1.0, 0.0], 'meta' => [], 'file' => 'a.php'],
    ], 2);

    $this->assertSame(['new'], array_column($this->store->search([1.0, 0.0], 10), 'ref'));
  }

  /**
   * Incremental deletes are keyed by file.
   */
  public function testDeleteByFile(): void {
    $this->store->upsertBatch([
      ['corpus' => 'code', 'ref' => 'a', 'vector' => [1.0, 0.0], 'meta' => [], 'file' => 'a.php'],
      ['corpus' => 'code', 'ref' => 'b', 'vector' => [1.0, 0.0], 'meta' => [], 'file' => 'b.php'],
    ], 2);

    $this->store->deleteByFile('a.php');
    $this->assertSame(['b'], array_column($this->store->search([1.0, 0.0], 10), 'ref'));
    // An empty path must not delete everything.
    $this->store->deleteByFile('');
    $this->assertSame(1, $this->store->count());
  }

  /**
   * Targeted and wholesale deletes both work.
   */
  public function testDeleteByCorpusAndRef(): void {
    $this->store->upsert('code', 'a', [1.0, 0.0]);
    $this->store->upsert('code', 'b', [1.0, 0.0]);
    $this->store->upsert('docs', 'c', [1.0, 0.0]);

    $this->store->delete('code', 'a');
    $this->assertSame(2, $this->store->count());
    $this->store->delete('code');
    $this->assertSame(1, $this->store->count());
    $this->store->delete();
    $this->assertSame(0, $this->store->count());
  }

}
