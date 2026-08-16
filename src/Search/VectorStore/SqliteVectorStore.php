<?php

declare(strict_types=1);

namespace Droost\Engine\Search\VectorStore;

use Droost\Engine\Search\Storage\SqliteTransactionTrait;

/**
 * A PDO/SQLite vector store, for semantic search with no site.
 *
 * Modelled on the portable Drupal store rather than the MariaDB-native one:
 * vectors are stored as JSON and scored in PHP, which is what makes the same
 * ranking reproducible on any backend. At single-project scale that is fine —
 * it is the same trade the portable store already makes on sites without
 * MariaDB 11.7+.
 *
 * Requires ext-pdo_sqlite.
 */
final class SqliteVectorStore implements VectorStoreInterface {

  use SqliteTransactionTrait;

  /**
   * The storage table name.
   */
  private const string TABLE = 'droost_search_vectors';

  /**
   * Whether the schema has been ensured on this connection.
   */
  private bool $ready = FALSE;

  /**
   * Constructs a SqliteVectorStore.
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
   * Opens a SQLite-backed vector store at a file path.
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
      throw new \RuntimeException('The SQLite vector store needs ext-pdo_sqlite, which is not loaded.');
    }
    return new self(new \PDO('sqlite:' . $path));
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function ensureSchema(int $dimension): void {
    if ($this->ready) {
      return;
    }
    // The dimension is not a column constraint here, exactly as in the
    // portable store: vectors are JSON, and dimension() reads it back from a
    // stored row. search() then refuses a query of the wrong width rather
    // than scoring it to noise.
    $this->pdo->exec(
      'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
      . "corpus TEXT NOT NULL DEFAULT '', "
      . "ref TEXT NOT NULL DEFAULT '', "
      . 'meta TEXT, '
      . 'vector TEXT NOT NULL, '
      . "file TEXT NOT NULL DEFAULT '', "
      . 'PRIMARY KEY (corpus, ref))'
    );
    $this->pdo->exec('CREATE INDEX IF NOT EXISTS droost_vectors_file ON ' . self::TABLE . ' (file)');
    $this->ready = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function upsert(string $corpus, string $ref, array $vector, array $meta = []): void {
    $this->ensureSchema(count($vector));
    $this->write($corpus, $ref, $vector, $meta, '');
  }

  /**
   * {@inheritdoc}
   */
  public function upsertBatch(array $rows, int $dimension): void {
    $this->ensureSchema($dimension);
    $this->runTransactional($this->pdo, function () use ($rows): void {
      foreach ($rows as $row) {
        $this->write(
          $row['corpus'],
          $row['ref'],
          is_array($row['vector'] ?? NULL) ? $row['vector'] : [],
          is_array($row['meta'] ?? NULL) ? $row['meta'] : [],
          is_string($row['file'] ?? NULL) ? $row['file'] : '',
        );
      }
    });
  }

  /**
   * {@inheritdoc}
   */
  public function replace(array $rows, int $dimension): void {
    $this->ensureSchema($dimension);
    // Collapse duplicate (corpus, ref) rows, last winning: the table is keyed
    // on that pair, so a ref recurring across two files (two global functions
    // of the same name, say) would otherwise abort the whole rebuild.
    $rows = self::dedupeByCorpusRef($rows);
    $this->runTransactional($this->pdo, function () use ($rows): void {
      $this->pdo->exec('DELETE FROM ' . self::TABLE);
      foreach ($rows as $row) {
        $this->write(
          is_string($row['corpus'] ?? NULL) ? $row['corpus'] : '',
          is_string($row['ref'] ?? NULL) ? $row['ref'] : '',
          is_array($row['vector'] ?? NULL) ? $row['vector'] : [],
          is_array($row['meta'] ?? NULL) ? $row['meta'] : [],
          is_string($row['file'] ?? NULL) ? $row['file'] : '',
        );
      }
    });
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByFile(string $file): void {
    if ($file === '' || !$this->tableExists()) {
      return;
    }
    $statement = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE file = ?');
    $statement->execute([$file]);
  }

  /**
   * {@inheritdoc}
   */
  public function search(array $vector, int $k = 10, ?string $corpus = NULL): array {
    if (!$this->tableExists()) {
      return [];
    }
    // Dimension drift would score every row 0.0 (cosine returns 0 on a
    // mismatch), presenting pure noise as ranked hits; return nothing instead.
    $current = $this->dimension();
    if ($current > 0 && $current !== count($vector)) {
      return [];
    }
    $sql = 'SELECT corpus, ref, meta, vector FROM ' . self::TABLE;
    $parameters = [];
    if ($corpus !== NULL) {
      $sql .= ' WHERE corpus = ?';
      $parameters[] = $corpus;
    }
    $statement = $this->pdo->prepare($sql);
    $statement->execute($parameters);

    $hits = [];
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      if (!is_array($row)) {
        continue;
      }
      $stored = is_string($row['vector'] ?? NULL) ? json_decode($row['vector'], TRUE) : NULL;
      if (!is_array($stored)) {
        continue;
      }
      $meta = is_string($row['meta'] ?? NULL) ? json_decode($row['meta'], TRUE) : NULL;
      $hits[] = [
        'corpus' => is_scalar($row['corpus'] ?? NULL) ? (string) $row['corpus'] : '',
        'ref' => is_scalar($row['ref'] ?? NULL) ? (string) $row['ref'] : '',
        'score' => Cosine::similarity($vector, $stored),
        'meta' => is_array($meta) ? $meta : [],
      ];
    }
    // Score desc, then (corpus, ref): ties have to break the same way in every
    // store, or the same index answers a query differently depending on where
    // it lives.
    usort($hits, static fn (array $a, array $b): int =>
      ($b['score'] <=> $a['score'])
        ?: ($a['corpus'] <=> $b['corpus'])
        ?: ($a['ref'] <=> $b['ref']));
    return array_slice($hits, 0, max(1, $k));
  }

  /**
   * {@inheritdoc}
   */
  public function delete(?string $corpus = NULL, ?string $ref = NULL): void {
    if (!$this->tableExists()) {
      return;
    }
    $sql = 'DELETE FROM ' . self::TABLE;
    $parameters = [];
    if ($corpus !== NULL) {
      $sql .= ' WHERE corpus = ?';
      $parameters[] = $corpus;
      if ($ref !== NULL) {
        $sql .= ' AND ref = ?';
        $parameters[] = $ref;
      }
    }
    $statement = $this->pdo->prepare($sql);
    $statement->execute($parameters);
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    if (!$this->tableExists()) {
      return 0;
    }
    $result = $this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE);
    $value = $result === FALSE ? NULL : $result->fetchColumn();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * {@inheritdoc}
   */
  public function dimension(): int {
    if (!$this->tableExists()) {
      return 0;
    }
    $result = $this->pdo->query('SELECT vector FROM ' . self::TABLE . ' LIMIT 1');
    $stored = $result === FALSE ? NULL : $result->fetchColumn();
    $decoded = is_string($stored) ? json_decode($stored, TRUE) : NULL;
    return is_array($decoded) ? count($decoded) : 0;
  }

  /**
   * Writes one row, replacing any row with the same (corpus, ref).
   *
   * @param string $corpus
   *   The corpus.
   * @param string $ref
   *   The reference.
   * @param array<array-key, mixed> $vector
   *   The embedding.
   * @param array<array-key, mixed> $meta
   *   The metadata.
   * @param string $file
   *   The owning file, for incremental deletes.
   */
  private function write(string $corpus, string $ref, array $vector, array $meta, string $file): void {
    $statement = $this->pdo->prepare(
      'INSERT INTO ' . self::TABLE . ' (corpus, ref, meta, vector, file) VALUES (?, ?, ?, ?, ?) '
      . 'ON CONFLICT(corpus, ref) DO UPDATE SET meta = excluded.meta, '
      . 'vector = excluded.vector, file = excluded.file'
    );
    $statement->execute([
      $corpus,
      $ref,
      (string) json_encode($meta),
      (string) json_encode(array_values($vector)),
      $file,
    ]);
  }

  /**
   * Collapses rows sharing a (corpus, ref) key, last write winning.
   *
   * @param array<int, array<string, mixed>> $rows
   *   The vector rows to store.
   *
   * @return array<int, array<string, mixed>>
   *   The de-duplicated rows.
   */
  private static function dedupeByCorpusRef(array $rows): array {
    $byKey = [];
    foreach ($rows as $row) {
      $corpus = is_string($row['corpus'] ?? NULL) ? $row['corpus'] : '';
      $ref = is_string($row['ref'] ?? NULL) ? $row['ref'] : '';
      $byKey[$corpus . "\0" . $ref] = $row;
    }
    return array_values($byKey);
  }

  /**
   * Whether the storage table exists.
   *
   * @return bool
   *   TRUE when it does.
   */
  private function tableExists(): bool {
    if ($this->ready) {
      return TRUE;
    }
    $statement = $this->pdo->prepare(
      "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?"
    );
    $statement->execute([self::TABLE]);
    return $statement->fetchColumn() !== FALSE;
  }

}
