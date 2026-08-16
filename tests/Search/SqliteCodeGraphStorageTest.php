<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Search;

use Droost\Engine\Search\Graph\CodeGraphStorageInterface;
use Droost\Engine\Search\Graph\SqliteCodeGraphStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SQLite code graph.
 *
 * These assert the CONTRACT the Drupal-backed store already implements. The
 * behaviours that matter are the ones a naive port gets wrong: reads before
 * the graph exists must answer empty rather than throw, resolveShortName must
 * match the final segment and not a substring, and the fan summary must count
 * DISTINCT pairs, exclude internal edges, and report its cap instead of
 * quietly truncating.
 */
#[CoversClass(SqliteCodeGraphStorage::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class SqliteCodeGraphStorageTest extends TestCase {

  /**
   * The store under test.
   */
  private SqliteCodeGraphStorage $graph;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->graph = SqliteCodeGraphStorage::open(':memory:');
  }

  /**
   * It satisfies the port.
   */
  public function testImplementsThePort(): void {
    $this->assertInstanceOf(CodeGraphStorageInterface::class, $this->graph);
  }

  /**
   * Every read before the graph is built answers empty, never throws.
   */
  public function testReadsAreSafeBeforeTheGraphExists(): void {
    $this->assertFalse($this->graph->isReady());
    $this->assertSame([], $this->graph->findSymbols('Node'));
    $this->assertSame([], $this->graph->callers('A\\B'));
    $this->assertSame([], $this->graph->dependencies('A\\B'));
    $this->assertSame([], $this->graph->resolveShortName('Node'));
    $this->assertSame(['symbols' => 0, 'edges' => 0], $this->graph->count());
    $summary = $this->graph->fanSummary('node');
    // "ready: false" is the whole point: 0 here means NOT MEASURED, and a
    // caller that cannot tell it from "no coupling" will publish a wrong fact.
    $this->assertFalse($summary['ready']);
    $this->assertSame(0, $summary['symbols']);
  }

  /**
   * A rebuild stores symbols and edges and replaces what was there.
   */
  public function testRebuildReplaces(): void {
    $this->graph->rebuild(
      [$this->symbol('A\\One', 'alpha'), $this->symbol('A\\Two', 'alpha')],
      [$this->edge('A\\One', 'A\\Two')],
    );
    $this->assertTrue($this->graph->isReady());
    $this->assertSame(['symbols' => 2, 'edges' => 1], $this->graph->count());

    $this->graph->rebuild([$this->symbol('A\\Three', 'alpha')], []);
    $this->assertSame(['symbols' => 1, 'edges' => 0], $this->graph->count());
  }

  /**
   * An incremental delta drops a file's rows and inserts replacements.
   */
  public function testApplyDeltaReplacesOnlyTheNamedFiles(): void {
    $this->graph->rebuild([
      $this->symbol('A\\One', 'alpha', 'a.php'),
      $this->symbol('A\\Two', 'alpha', 'b.php'),
    ], [
      $this->edge('A\\One', 'A\\Two', 'a.php'),
      $this->edge('A\\Two', 'A\\One', 'b.php'),
    ]);

    $this->graph->applyDelta(['a.php'], [$this->symbol('A\\OneB', 'alpha', 'a.php')], []);

    $this->assertSame(['symbols' => 2, 'edges' => 1], $this->graph->count());
    $names = array_column($this->graph->findSymbols('A\\'), 'fqcn');
    sort($names);
    $this->assertSame(['A\\OneB', 'A\\Two'], $names);
  }

  /**
   * Symbol search is case-insensitive, deterministic, and limited.
   */
  public function testFindSymbolsIsCaseInsensitiveAndOrdered(): void {
    $this->graph->rebuild([
      $this->symbol('A\\Zebra', 'alpha'),
      $this->symbol('A\\Apple', 'alpha'),
      $this->symbol('B\\Other', 'beta'),
    ], []);

    $this->assertSame(
      ['A\\Apple', 'A\\Zebra'],
      array_column($this->graph->findSymbols('a\\'), 'fqcn'),
    );
    // Ordered, so a truncated result is the same subset every call.
    $this->assertSame(['A\\Apple'], array_column($this->graph->findSymbols('A\\', 1), 'fqcn'));
  }

  /**
   * A truncated search is stable: the same call returns the same subset.
   *
   * This is the contract the port actually promises. It is deliberately NOT
   * "the same subset as the Drupal store" — the two agree on which symbols
   * MATCH, but they sort through their own database's collation, and MariaDB
   * orders punctuation differently from SQLite. Raise the limit past the
   * match count if you need cross-store-identical output.
   */
  public function testTruncatedSearchIsStableWithinTheStore(): void {
    $symbols = [];
    for ($i = 0; $i < 30; $i++) {
      $symbols[] = $this->symbol(sprintf('A\\Thing%02d', $i), 'alpha');
    }
    $this->graph->rebuild($symbols, []);

    $first = $this->graph->findSymbols('Thing', 5);
    $this->assertSame($first, $this->graph->findSymbols('Thing', 5));
    $this->assertCount(5, $first);
    // And the full set is reachable by raising the limit, which is what a
    // caller needing determinism across stores is told to do.
    $this->assertCount(30, $this->graph->findSymbols('Thing', 1000));
  }

  /**
   * A namespace separator is a LIKE escape hazard, not a wildcard.
   */
  public function testUnderscoreAndBackslashAreMatchedLiterally(): void {
    $this->graph->rebuild([
      $this->symbol('A\\My_Thing', 'alpha'),
      $this->symbol('A\\MyXThing', 'alpha'),
    ], []);

    // Without an ESCAPE clause SQLite treats "_" as a single-character
    // wildcard, so this would also match MyXThing.
    $this->assertSame(
      ['A\\My_Thing'],
      array_column($this->graph->findSymbols('My_Thing'), 'fqcn'),
    );
  }

  /**
   * Edges are queryable in both directions.
   */
  public function testCallersAndDependencies(): void {
    $this->graph->rebuild([], [
      $this->edge('A\\One', 'A\\Target'),
      $this->edge('A\\Two', 'A\\Target'),
      $this->edge('A\\Target', 'A\\Three'),
    ]);

    $this->assertSame(['A\\One', 'A\\Two'], array_column($this->graph->callers('A\\Target'), 'src'));
    $this->assertSame(['A\\Three'], array_column($this->graph->dependencies('A\\Target'), 'dst'));
  }

  /**
   * Short-name resolution matches the final segment, not a substring.
   */
  public function testResolveShortNameMatchesTheFinalSegmentOnly(): void {
    $this->graph->rebuild([
      $this->symbol('Drupal\\a\\PathGuard', 'alpha'),
      $this->symbol('Drupal\\b\\PathGuardTest', 'alpha'),
      $this->symbol('Drupal\\c\\NotIt', 'alpha'),
      // A non-class-like kind must not resolve, even with the right name.
      ['fqcn' => 'Drupal\\d\\PathGuard::run', 'kind' => 'method', 'file' => 'x.php', 'line' => 1, 'module' => 'alpha'],
    ], []);

    $this->assertSame(['Drupal\\a\\PathGuard'], $this->graph->resolveShortName('PathGuard'));
    // Case-insensitive.
    $this->assertSame(['Drupal\\a\\PathGuard'], $this->graph->resolveShortName('pathguard'));
    $this->assertSame([], $this->graph->resolveShortName('Nonexistent'));
  }

  /**
   * The fan summary counts across the module boundary, in both directions.
   */
  public function testFanSummaryExcludesInternalEdges(): void {
    $this->graph->rebuild([
      $this->symbol('A\\One', 'alpha'),
      $this->symbol('A\\Two', 'alpha'),
      $this->symbol('B\\One', 'beta'),
      $this->symbol('C\\One', 'gamma'),
    ], [
      // Internal to alpha — excluded: the module's own prose covers it.
      $this->edge('A\\One', 'A\\Two'),
      // Outbound to beta and gamma.
      $this->edge('A\\One', 'B\\One'),
      $this->edge('A\\Two', 'B\\One'),
      $this->edge('A\\One', 'C\\One'),
      // Inbound from gamma.
      $this->edge('C\\One', 'A\\One'),
    ]);

    $summary = $this->graph->fanSummary('alpha');
    $this->assertTrue($summary['ready']);
    $this->assertSame(2, $summary['symbols']);
    // Heaviest coupling first: beta has 2 edges, gamma 1.
    $this->assertSame(
      [
        ['module' => 'beta', 'edges' => 2, 'symbols' => 1],
        ['module' => 'gamma', 'edges' => 1, 'symbols' => 1],
      ],
      $summary['outbound'],
    );
    $this->assertSame(
      [['module' => 'gamma', 'edges' => 1, 'symbols' => 1]],
      $summary['inbound'],
    );
    $this->assertSame(3, $summary['outbound_edges']);
    $this->assertSame(1, $summary['inbound_edges']);
    $this->assertFalse($summary['truncated']);
  }

  /**
   * A duplicated symbol row must not multiply the edge counts.
   */
  public function testFanSummaryCountsDistinctPairs(): void {
    $this->graph->rebuild([
      $this->symbol('A\\One', 'alpha'),
      // The symbol table is not unique on fqcn — a re-index that appends
      // before its delta lands puts two rows behind one name, and a SQL
      // aggregate over the three-table join would double every count.
      $this->symbol('A\\One', 'alpha', 'duplicate.php'),
      $this->symbol('B\\One', 'beta'),
    ], [$this->edge('A\\One', 'B\\One')]);

    $summary = $this->graph->fanSummary('alpha');
    $this->assertSame([['module' => 'beta', 'edges' => 1, 'symbols' => 1]], $summary['outbound']);
    $this->assertSame(1, $summary['outbound_edges']);
  }

  /**
   * A symbol with no owning module is not bucketed under an empty name.
   */
  public function testFanSummarySkipsUnattributedSymbols(): void {
    $this->graph->rebuild([
      $this->symbol('A\\One', 'alpha'),
      $this->symbol('B\\One', ''),
    ], [$this->edge('A\\One', 'B\\One')]);

    $summary = $this->graph->fanSummary('alpha');
    $this->assertSame([], $summary['outbound']);
    $this->assertSame(0, $summary['outbound_edges']);
  }

  /**
   * Reaching the cap is reported, not swallowed.
   */
  public function testFanSummaryReportsTruncation(): void {
    $symbols = [$this->symbol('A\\One', 'alpha')];
    $edges = [];
    for ($i = 0; $i < 10; $i++) {
      $symbols[] = $this->symbol('B\\Sym' . $i, 'beta');
      $edges[] = $this->edge('A\\One', 'B\\Sym' . $i);
    }
    $this->graph->rebuild($symbols, $edges);

    $capped = $this->graph->fanSummary('alpha', 3);
    $this->assertTrue($capped['truncated']);
    $this->assertSame(3, $capped['outbound_edges']);

    $whole = $this->graph->fanSummary('alpha', 100);
    $this->assertFalse($whole['truncated']);
    $this->assertSame(10, $whole['outbound_edges']);
  }

  /**
   * Single-row writes work, and clear() empties without dropping.
   */
  public function testAddAndClear(): void {
    $this->graph->addSymbol('A\\One', 'class', 'a.php', 12, 'alpha');
    $this->graph->addEdge('A\\One', 'A\\Two', 'calls');
    $this->assertSame(['symbols' => 1, 'edges' => 1], $this->graph->count());

    $this->graph->clear();
    $this->assertSame(['symbols' => 0, 'edges' => 0], $this->graph->count());
    // Cleared, not dropped: the graph is still built, it just holds nothing.
    $this->assertTrue($this->graph->isReady());
  }

  /**
   * An unknown edge column is refused rather than interpolated.
   */
  public function testUnknownEdgeColumnIsRefused(): void {
    $this->graph->rebuild([$this->symbol('A\\One', 'alpha')], []);
    // The column reaches SQL by concatenation, so the whitelist is the only
    // thing between it and an injection point. Its callers pass literals
    // today; this asserts the guard is there for the ones that come later.
    $method = new \ReflectionMethod($this->graph, 'edgesBy');
    $this->expectException(\InvalidArgumentException::class);
    $method->invoke($this->graph, 'kind', 'x', 10);
  }

  /**
   * Builds a symbol row.
   *
   * @param string $fqcn
   *   The name.
   * @param string $module
   *   The owning module.
   * @param string $file
   *   The file.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function symbol(string $fqcn, string $module, string $file = 'a.php'): array {
    return ['fqcn' => $fqcn, 'kind' => 'class', 'file' => $file, 'line' => 1, 'module' => $module];
  }

  /**
   * Builds an edge row.
   *
   * @param string $src
   *   The source.
   * @param string $dst
   *   The destination.
   * @param string $file
   *   The file.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function edge(string $src, string $dst, string $file = 'a.php'): array {
    return ['src' => $src, 'dst' => $dst, 'kind' => 'calls', 'file' => $file];
  }

}
