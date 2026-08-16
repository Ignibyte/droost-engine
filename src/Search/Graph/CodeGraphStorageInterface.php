<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Graph;

/**
 * Stores and queries the code graph: symbols and the edges between them.
 *
 * Lexical and embedding-free — symbol lookup and graph queries work with no
 * AI/embedding backend configured. Edges are structural relationships
 * (extends, implements, uses, instantiates, calls).
 *
 * A PORT, not a lift. The Drupal-backed implementation is built on Drupal's
 * database API and stays in the module; this interface exists so the same
 * indexer and the same queries can run against a bare checkout — see
 * SqliteCodeGraphStorage.
 *
 * Every read tolerates an unbuilt graph and answers empty rather than throwing.
 * That is a contract, not politeness: "nothing depends on this" and "nobody
 * measured" have to stay distinguishable, which is what isReady() and
 * fanSummary()'s `ready` flag are for.
 */
interface CodeGraphStorageInterface {

  /**
   * Ensures the symbol and edge storage exists.
   */
  public function ensureSchema(): void;

  /**
   * Whether the graph has been built.
   *
   * @return bool
   *   TRUE once an index run has created the storage.
   */
  public function isReady(): bool;

  /**
   * Removes all graph data (for a full rebuild).
   */
  public function clear(): void;

  /**
   * Atomically replaces the graph with the given symbols and edges.
   *
   * An interrupted rebuild must roll back and leave the previous graph intact.
   *
   * @param array<int, array<string, mixed>> $symbols
   *   The symbols to store (each keyed fqcn/kind/file/line/module).
   * @param array<int, array<string, mixed>> $edges
   *   The edges to store (each keyed src/dst/kind/file).
   */
  public function rebuild(array $symbols, array $edges): void;

  /**
   * Applies an incremental delta: drop files' rows, insert replacements.
   *
   * @param array<int, string> $dropFiles
   *   Paths whose symbol and edge rows are removed first.
   * @param array<int, array<string, mixed>> $symbols
   *   Replacement symbols (for the re-parsed files).
   * @param array<int, array<string, mixed>> $edges
   *   Replacement edges (each carries its file).
   */
  public function applyDelta(array $dropFiles, array $symbols, array $edges): void;

  /**
   * Records a symbol.
   *
   * @param string $fqcn
   *   The fully-qualified name.
   * @param string $kind
   *   The kind: class, interface, trait, enum, function, or method.
   * @param string $file
   *   The file path.
   * @param int $line
   *   The line number.
   * @param string $module
   *   The owning extension.
   */
  public function addSymbol(string $fqcn, string $kind, string $file, int $line, string $module): void;

  /**
   * Records a directed edge between two symbols.
   *
   * @param string $src
   *   The source FQCN.
   * @param string $dst
   *   The destination FQCN.
   * @param string $kind
   *   The kind: extends, implements, uses, instantiates, or calls.
   */
  public function addEdge(string $src, string $dst, string $kind): void;

  /**
   * Finds symbols whose FQCN matches a substring (lexical, case-insensitive).
   *
   * The order must be deterministic WITHIN a store: callers report truncation,
   * so an over-the-limit result has to return the same subset every call
   * rather than an arbitrary slice of database order.
   *
   * It is NOT identical ACROSS stores, and that is a documented limit rather
   * than a bug to chase. Implementations agree exactly on which symbols match
   * — verified against a real 33k-symbol graph — but they sort through their
   * database's collation, and MariaDB's UCA collation orders punctuation
   * differently from SQLite's. `Canvas\PHPStan\…` and `canvas_stark_…` swap
   * places, so a truncating limit can keep a different row. Reproducing one
   * engine's collation in the other is not portably expressible through
   * Drupal's query builder.
   *
   * A caller that needs a cross-store-identical result should raise the limit
   * past the match count (the SETS are identical) and order the rows itself.
   *
   * @param string $pattern
   *   The substring to match.
   * @param int $limit
   *   Maximum rows.
   *
   * @return array<int, array<string, mixed>>
   *   Matching symbol rows (fqcn, kind, file, line, module).
   */
  public function findSymbols(string $pattern, int $limit = 20): array;

  /**
   * Returns edges whose destination is the given symbol (who references it).
   *
   * @param string $fqcn
   *   The destination FQCN.
   * @param int $limit
   *   Maximum rows.
   *
   * @return array<int, array<string, mixed>>
   *   Edge rows (src, dst, kind).
   */
  public function callers(string $fqcn, int $limit = 100): array;

  /**
   * Returns edges whose source is the given symbol (what it references).
   *
   * @param string $fqcn
   *   The source FQCN.
   * @param int $limit
   *   Maximum rows.
   *
   * @return array<int, array<string, mixed>>
   *   Edge rows (src, dst, kind).
   */
  public function dependencies(string $fqcn, int $limit = 100): array;

  /**
   * Resolves a bare short name to the distinct FQCNs whose final segment is it.
   *
   * The graph is keyed by fully-qualified name, so a bare short class name
   * matches nothing. "PathGuard" resolves to Drupal\...\PathGuard but NOT to
   * PathGuardTest or a ::pathGuard method — the match is on the final namespace
   * segment, exactly, case-insensitively, and only for class-like kinds.
   *
   * @param string $short
   *   The bare short name (no namespace separator).
   * @param int $limit
   *   Maximum distinct FQCNs to return.
   *
   * @return array<int, string>
   *   Distinct matching FQCNs, possibly empty.
   */
  public function resolveShortName(string $short, int $limit = 10): array;

  /**
   * Returns symbol and edge counts.
   *
   * @return array{symbols: int, edges: int}
   *   The counts; zeroes when the graph has never been built.
   */
  public function count(): array;

  /**
   * Summarises one module's cross-module coupling, both directions.
   *
   * Edges within the module are excluded: internal coupling is what a reader
   * can already see, the boundary is what they cannot.
   *
   * Counts come from DISTINCT (near, far) pairs rather than a SQL aggregate.
   * The symbol table is not unique on fqcn, so a COUNT over the three-table
   * join would silently multiply.
   *
   * @param string $module
   *   The module machine name.
   * @param int $cap
   *   Maximum distinct edges to read per direction. Reaching it is reported,
   *   never swallowed — a truncated summary must not read as a complete one.
   *
   * @return array{ready: bool, symbols: int, outbound: array<int, array{module: string, edges: int, symbols: int}>, inbound: array<int, array{module: string, edges: int, symbols: int}>, outbound_edges: int, inbound_edges: int, truncated: bool}
   *   The summary. `ready` is FALSE when the graph has never been built, in
   *   which case every count is 0 and means "not measured" — never "none".
   */
  public function fanSummary(string $module, int $cap = 2000): array;

}
