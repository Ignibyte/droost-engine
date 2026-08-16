<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Graph;

use Droost\Engine\Search\Storage\SqliteTransactionTrait;

/**
 * A PDO/SQLite code graph, for querying a repository with no site.
 *
 * The Drupal-backed store is what a running site uses; this is what makes
 * symbol lookup, callers/dependencies and the module fan summary answerable
 * against a bare checkout.
 *
 * Requires ext-pdo_sqlite.
 */
final class SqliteCodeGraphStorage implements CodeGraphStorageInterface {

  use SqliteTransactionTrait;

  /**
   * The symbols table name.
   */
  private const string SYMBOLS = 'droost_search_symbol';

  /**
   * The edges table name.
   */
  private const string EDGES = 'droost_search_edge';

  /**
   * The LIKE escape character, as a SQL literal.
   *
   * SQLite's LIKE has NO default escape character, so a raw LOWER()-wrapped
   * LIKE needs one declared or the backslashes escapeLike() inserts stay
   * literal — and every namespaced FQCN then matches nothing.
   */
  private const string LIKE_ESCAPE = " ESCAPE '\\'";

  /**
   * Whether the schema has been ensured on this connection.
   */
  private bool $ready = FALSE;

  /**
   * Constructs a SqliteCodeGraphStorage.
   *
   * @param \PDO $pdo
   *   An open SQLite connection.
   */
  public function __construct(
    private readonly \PDO $pdo,
  ) {
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
  }

  /**
   * Opens a SQLite-backed graph at a file path.
   *
   * @param string $path
   *   The database file path, or ":memory:".
   *
   * @return self
   *   The store.
   *
   * @throws \RuntimeException
   *   When ext-pdo_sqlite is unavailable.
   */
  public static function open(string $path): self {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), TRUE)) {
      throw new \RuntimeException('The SQLite code graph needs ext-pdo_sqlite, which is not loaded.');
    }
    return new self(new \PDO('sqlite:' . $path));
  }

  /**
   * {@inheritdoc}
   */
  public function ensureSchema(): void {
    if ($this->ready) {
      return;
    }
    $this->pdo->exec(
      'CREATE TABLE IF NOT EXISTS ' . self::SYMBOLS . ' ('
      . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
      . "fqcn TEXT NOT NULL DEFAULT '', "
      . "kind TEXT NOT NULL DEFAULT '', "
      . "file TEXT NOT NULL DEFAULT '', "
      . 'line INTEGER NOT NULL DEFAULT 0, '
      . "module TEXT NOT NULL DEFAULT '')"
    );
    $this->pdo->exec(
      'CREATE TABLE IF NOT EXISTS ' . self::EDGES . ' ('
      . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
      . "src TEXT NOT NULL DEFAULT '', "
      . "dst TEXT NOT NULL DEFAULT '', "
      . "kind TEXT NOT NULL DEFAULT '', "
      . "file TEXT NOT NULL DEFAULT '')"
    );
    // The same indexes the Drupal schema declares. Without them the fan
    // summary's two self-joins degrade to full scans, which is the difference
    // between a wiki factsheet and a hung command on a real codebase.
    $this->pdo->exec('CREATE INDEX IF NOT EXISTS droost_symbol_fqcn ON ' . self::SYMBOLS . ' (fqcn)');
    $this->pdo->exec('CREATE INDEX IF NOT EXISTS droost_symbol_module ON ' . self::SYMBOLS . ' (module)');
    $this->pdo->exec('CREATE INDEX IF NOT EXISTS droost_symbol_file ON ' . self::SYMBOLS . ' (file)');
    $this->pdo->exec('CREATE INDEX IF NOT EXISTS droost_edge_src ON ' . self::EDGES . ' (src)');
    $this->pdo->exec('CREATE INDEX IF NOT EXISTS droost_edge_dst ON ' . self::EDGES . ' (dst)');
    $this->pdo->exec('CREATE INDEX IF NOT EXISTS droost_edge_file ON ' . self::EDGES . ' (file)');
    $this->ready = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isReady(): bool {
    return $this->tableExists(self::SYMBOLS);
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): void {
    foreach ([self::SYMBOLS, self::EDGES] as $table) {
      if ($this->tableExists($table)) {
        $this->pdo->exec('DELETE FROM ' . $table);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rebuild(array $symbols, array $edges): void {
    $this->ensureSchema();
    $this->runTransactional($this->pdo, function () use ($symbols, $edges): void {
      $this->pdo->exec('DELETE FROM ' . self::SYMBOLS);
      $this->pdo->exec('DELETE FROM ' . self::EDGES);
      $this->insertBatched(self::SYMBOLS, ['fqcn', 'kind', 'file', 'line', 'module'], $symbols);
      $this->insertBatched(self::EDGES, ['src', 'dst', 'kind', 'file'], $edges);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function applyDelta(array $dropFiles, array $symbols, array $edges): void {
    $this->ensureSchema();
    $this->runTransactional($this->pdo, function () use ($dropFiles, $symbols, $edges): void {
      if ($dropFiles !== []) {
        $this->deleteByFile(self::SYMBOLS, $dropFiles);
        $this->deleteByFile(self::EDGES, $dropFiles);
      }
      $this->insertBatched(self::SYMBOLS, ['fqcn', 'kind', 'file', 'line', 'module'], $symbols);
      $this->insertBatched(self::EDGES, ['src', 'dst', 'kind', 'file'], $edges);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function addSymbol(string $fqcn, string $kind, string $file, int $line, string $module): void {
    $this->ensureSchema();
    $statement = $this->pdo->prepare(
      'INSERT INTO ' . self::SYMBOLS . ' (fqcn, kind, file, line, module) VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$fqcn, $kind, $file, $line, $module]);
  }

  /**
   * {@inheritdoc}
   */
  public function addEdge(string $src, string $dst, string $kind): void {
    $this->ensureSchema();
    $statement = $this->pdo->prepare(
      "INSERT INTO " . self::EDGES . " (src, dst, kind, file) VALUES (?, ?, ?, '')"
    );
    $statement->execute([$src, $dst, $kind]);
  }

  /**
   * {@inheritdoc}
   */
  public function findSymbols(string $pattern, int $limit = 20): array {
    if (!$this->isReady()) {
      return [];
    }
    $statement = $this->pdo->prepare(
      'SELECT fqcn, kind, file, line, module FROM ' . self::SYMBOLS
      . ' WHERE LOWER(fqcn) LIKE ?' . self::LIKE_ESCAPE
      // COLLATE NOCASE matches the incumbent store's ordering, not SQLite's
      // default. MariaDB's utf8mb4 collation is case-insensitive, SQLite's
      // BINARY is not, so without this the two return DIFFERENT top-20 sets
      // for the same query — which breaks the "same subset every call"
      // promise the moment a caller switches stores. Found by diffing both
      // against the harness's real 33k-symbol graph, not by reading.
      . ' ORDER BY fqcn COLLATE NOCASE, line LIMIT ' . max(1, $limit)
    );
    $statement->execute(['%' . self::escapeLike(mb_strtolower($pattern)) . '%']);
    $rows = [];
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      if (!is_array($row)) {
        continue;
      }
      $rows[] = [
        'fqcn' => $row['fqcn'],
        'kind' => $row['kind'],
        'file' => $row['file'],
        'line' => $row['line'],
        'module' => $row['module'],
      ];
    }
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function callers(string $fqcn, int $limit = 100): array {
    return $this->edgesBy('dst', $fqcn, $limit);
  }

  /**
   * {@inheritdoc}
   */
  public function dependencies(string $fqcn, int $limit = 100): array {
    return $this->edgesBy('src', $fqcn, $limit);
  }

  /**
   * {@inheritdoc}
   */
  public function resolveShortName(string $short, int $limit = 10): array {
    if ($short === '' || !$this->isReady()) {
      return [];
    }
    $lower = mb_strtolower($short);
    // Suffix match, not substring: the final segment is what resolves, and
    // "%node" is a far smaller candidate set than "%node%" (which would match
    // every class in a "node" namespace and truncate the real one away). The
    // 5000 bound is a heuristic; the PHP filter below is the actual rule.
    $statement = $this->pdo->prepare(
      'SELECT fqcn FROM ' . self::SYMBOLS
      . " WHERE kind IN ('class', 'interface', 'trait', 'enum')"
      . ' AND LOWER(fqcn) LIKE ?' . self::LIKE_ESCAPE
      . ' ORDER BY fqcn COLLATE NOCASE LIMIT 5000'
    );
    $statement->execute(['%' . self::escapeLike($lower)]);
    $out = [];
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $fqcn = is_array($row) && is_string($row['fqcn'] ?? NULL) ? $row['fqcn'] : '';
      if ($fqcn === '') {
        continue;
      }
      $pos = strrpos($fqcn, '\\');
      $base = $pos !== FALSE ? substr($fqcn, $pos + 1) : $fqcn;
      if (mb_strtolower($base) === $lower) {
        $out[$fqcn] = TRUE;
        if (count($out) >= max(1, $limit)) {
          break;
        }
      }
    }
    return array_keys($out);
  }

  /**
   * {@inheritdoc}
   */
  public function count(): array {
    return [
      'symbols' => $this->countTable(self::SYMBOLS),
      'edges' => $this->countTable(self::EDGES),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fanSummary(string $module, int $cap = 2000): array {
    $empty = [
      'ready' => FALSE,
      'symbols' => 0,
      'outbound' => [],
      'inbound' => [],
      'outbound_edges' => 0,
      'inbound_edges' => 0,
      'truncated' => FALSE,
    ];
    if ($module === '' || !$this->isReady() || !$this->tableExists(self::EDGES)) {
      return $empty;
    }
    $cap = max(1, $cap);

    $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::SYMBOLS . ' WHERE module = ?');
    $statement->execute([$module]);
    $symbols = $statement->fetchColumn();

    $out = $this->fanDirection($module, 'src', 'dst', $cap);
    $in = $this->fanDirection($module, 'dst', 'src', $cap);

    return [
      'ready' => TRUE,
      'symbols' => is_numeric($symbols) ? (int) $symbols : 0,
      'outbound' => $out['rows'],
      'inbound' => $in['rows'],
      'outbound_edges' => $out['edges'],
      'inbound_edges' => $in['edges'],
      'truncated' => $out['truncated'] || $in['truncated'],
    ];
  }

  /**
   * Counts one direction of a module's cross-module edges.
   *
   * @param string $module
   *   The module machine name.
   * @param string $near
   *   The edge column joined to the module's own symbols: src or dst.
   * @param string $far
   *   The edge column joined to the other module's symbols.
   * @param int $cap
   *   Maximum distinct edges to read.
   *
   * @return array{rows: array<int, array{module: string, edges: int, symbols: int}>, edges: int, truncated: bool}
   *   Per-module counts, descending by edge count then module name.
   */
  private function fanDirection(string $module, string $near, string $far, int $cap): array {
    // Whitelisted rather than interpolated on trust: these are internal
    // literals today, and a future caller passing a column name should get an
    // exception instead of a SQL injection point.
    foreach ([$near, $far] as $column) {
      if ($column !== 'src' && $column !== 'dst') {
        throw new \InvalidArgumentException(sprintf('Unknown edge column "%s".', $column));
      }
    }
    $statement = $this->pdo->prepare(
      'SELECT DISTINCT e.' . $near . ' AS near_end, e.' . $far . ' AS far_end, f.module AS other'
      . ' FROM ' . self::EDGES . ' e'
      . ' INNER JOIN ' . self::SYMBOLS . ' n ON n.fqcn = e.' . $near
      . ' INNER JOIN ' . self::SYMBOLS . ' f ON f.fqcn = e.' . $far
      // A symbol whose owning module is unrecorded cannot be attributed to a
      // boundary, and an empty bucket would read as a real one.
      . " WHERE n.module = ? AND f.module <> ? AND f.module <> ''"
      // One past the cap, so hitting it is distinguishable from filling it.
      . ' LIMIT ' . ($cap + 1)
    );
    $statement->execute([$module, $module]);

    $edges = [];
    $symbols = [];
    $seen = 0;
    $truncated = FALSE;
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      if (!is_array($row)) {
        continue;
      }
      $seen++;
      if ($seen > $cap) {
        $truncated = TRUE;
        break;
      }
      $other = is_string($row['other'] ?? NULL) ? $row['other'] : '';
      $fqcn = is_string($row['far_end'] ?? NULL) ? $row['far_end'] : '';
      if ($other === '' || $fqcn === '') {
        continue;
      }
      $edges[$other] = ($edges[$other] ?? 0) + 1;
      // The far end is what "touched symbols" counts: outbound, the symbols
      // this module reaches into; inbound, the symbols reaching in.
      $symbols[$other][$fqcn] = TRUE;
    }

    $rows = [];
    foreach ($edges as $other => $edgeCount) {
      $rows[] = [
        'module' => (string) $other,
        'edges' => $edgeCount,
        'symbols' => count($symbols[$other] ?? []),
      ];
    }
    // Heaviest coupling first, ties broken by name: a wiki page regenerated
    // with no code change between must be byte-identical, which a count-only
    // sort does not guarantee.
    usort($rows, static fn (array $a, array $b): int
      => [$b['edges'], $a['module']] <=> [$a['edges'], $b['module']]);
    return [
      'rows' => $rows,
      'edges' => array_sum($edges),
      'truncated' => $truncated,
    ];
  }

  /**
   * Returns edges filtered by a column equalling a value.
   *
   * @param string $column
   *   The column to match: src or dst.
   * @param string $value
   *   The FQCN to match.
   * @param int $limit
   *   Maximum rows.
   *
   * @return array<int, array<string, mixed>>
   *   Edge rows.
   */
  private function edgesBy(string $column, string $value, int $limit = 100): array {
    if ($column !== 'src' && $column !== 'dst') {
      throw new \InvalidArgumentException(sprintf('Unknown edge column "%s".', $column));
    }
    if (!$this->tableExists(self::EDGES)) {
      return [];
    }
    $statement = $this->pdo->prepare(
      'SELECT src, dst, kind FROM ' . self::EDGES
      . ' WHERE ' . $column . ' = ? LIMIT ' . max(1, $limit)
    );
    $statement->execute([$value]);
    $rows = [];
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      if (is_array($row)) {
        $rows[] = ['src' => $row['src'], 'dst' => $row['dst'], 'kind' => $row['kind']];
      }
    }
    return $rows;
  }

  /**
   * Deletes a table's rows for the given files, in chunks.
   *
   * @param string $table
   *   The table.
   * @param array<int, string> $files
   *   The file paths.
   */
  private function deleteByFile(string $table, array $files): void {
    foreach (array_chunk(array_values($files), 500) as $chunk) {
      $placeholders = implode(',', array_fill(0, count($chunk), '?'));
      $statement = $this->pdo->prepare(
        'DELETE FROM ' . $table . ' WHERE file IN (' . $placeholders . ')'
      );
      $statement->execute(array_values($chunk));
    }
  }

  /**
   * Inserts rows into a table in batches.
   *
   * @param string $table
   *   The table.
   * @param array<int, string> $fields
   *   The field names, in order.
   * @param array<int, array<string, mixed>> $rows
   *   The rows, each keyed by field name.
   */
  private function insertBatched(string $table, array $fields, array $rows): void {
    if ($rows === []) {
      return;
    }
    $statement = $this->pdo->prepare(
      'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES ('
      . implode(', ', array_fill(0, count($fields), '?')) . ')'
    );
    foreach ($rows as $row) {
      $values = [];
      foreach ($fields as $field) {
        // 'line' is an integer column; default it to 0, others to ''.
        $value = $row[$field] ?? ($field === 'line' ? 0 : '');
        $values[] = is_scalar($value) ? $value : '';
      }
      $statement->execute($values);
    }
  }

  /**
   * Counts rows in a table.
   *
   * @param string $table
   *   The table.
   *
   * @return int
   *   The row count, or 0 when the table does not exist yet.
   */
  private function countTable(string $table): int {
    if (!$this->tableExists($table)) {
      return 0;
    }
    $count = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
    $value = $count === FALSE ? NULL : $count->fetchColumn();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Whether a table exists.
   *
   * @param string $table
   *   The table name.
   *
   * @return bool
   *   TRUE when it does.
   */
  private function tableExists(string $table): bool {
    if ($this->ready) {
      return TRUE;
    }
    $statement = $this->pdo->prepare(
      "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?"
    );
    $statement->execute([$table]);
    return $statement->fetchColumn() !== FALSE;
  }

  /**
   * Escapes LIKE wildcards, matching Drupal's Connection::escapeLike().
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   The escaped value.
   */
  private static function escapeLike(string $value): string {
    return addcslashes($value, '\\%_');
  }

}
