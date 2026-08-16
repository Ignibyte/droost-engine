<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Storage;

use Droost\Engine\Search\FileManifestInterface;

/**
 * A PDO/SQLite file manifest, for indexing a repository with no site.
 *
 * The Drupal-backed manifest is the implementation a running site uses; this
 * is the one that makes `droost` usable against a bare checkout, which is what
 * the `ext-pdo_sqlite` suggest has always been for.
 *
 * Requires ext-pdo_sqlite. The constructor asserts that rather than letting a
 * missing extension surface later as an unrelated SQL error.
 */
final class SqliteFileManifest implements FileManifestInterface {

  /**
   * The manifest table name.
   */
  private const string TABLE = 'droost_search_file';

  /**
   * Whether the schema has been ensured on this connection.
   */
  private bool $ready = FALSE;

  /**
   * Constructs a SqliteFileManifest.
   *
   * @param \PDO $pdo
   *   An open SQLite connection.
   *
   * @throws \RuntimeException
   *   When the connection is not usable for this store.
   */
  public function __construct(
    private readonly \PDO $pdo,
  ) {
    // Exceptions rather than silent FALSE returns: a manifest that quietly
    // fails to write turns an incremental index into a permanent full
    // re-index, which looks like slowness rather than breakage.
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
  }

  /**
   * Opens a SQLite-backed manifest at a file path.
   *
   * @param string $path
   *   The database file path; the directory must exist. ":memory:" is
   *   accepted and is what the tests use.
   *
   * @return self
   *   The manifest.
   *
   * @throws \RuntimeException
   *   When ext-pdo_sqlite is unavailable.
   */
  public static function open(string $path): self {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), TRUE)) {
      throw new \RuntimeException('The SQLite manifest needs ext-pdo_sqlite, which is not loaded.');
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
      'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
      . 'file TEXT NOT NULL PRIMARY KEY, '
      . "hash TEXT NOT NULL DEFAULT '', "
      . "scope TEXT NOT NULL DEFAULT '', "
      . "type TEXT NOT NULL DEFAULT '', "
      . 'indexed_at INTEGER NOT NULL DEFAULT 0)'
    );
    $this->ready = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    if (!$this->tableExists()) {
      return [];
    }
    $rows = [];
    $statement = $this->pdo->query('SELECT file, hash, scope FROM ' . self::TABLE);
    if ($statement === FALSE) {
      return [];
    }
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      if (!is_array($row) || !is_string($row['file'] ?? NULL)) {
        continue;
      }
      $rows[$row['file']] = [
        'hash' => is_string($row['hash'] ?? NULL) ? $row['hash'] : '',
        'scope' => is_string($row['scope'] ?? NULL) ? $row['scope'] : '',
      ];
    }
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function replaceAll(array $rows, int $timestamp): void {
    $this->ensureSchema();
    $this->transactional(function () use ($rows, $timestamp): void {
      $this->pdo->exec('DELETE FROM ' . self::TABLE);
      $this->insertBatched($rows, $timestamp);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function apply(array $upserts, array $removals, int $timestamp): void {
    $this->ensureSchema();
    $this->transactional(function () use ($upserts, $removals, $timestamp): void {
      $this->deleteFiles($removals);
      // The changed files are deleted before reinsertion rather than upserted,
      // matching the Drupal implementation exactly — the two stores must agree
      // on behaviour, not merely on method names.
      $this->deleteFiles(array_column($upserts, 'file'));
      $this->insertBatched($upserts, $timestamp);
    });
  }

  /**
   * Deletes manifest rows by path, in chunks.
   *
   * @param array<int, string> $paths
   *   The paths to remove.
   */
  private function deleteFiles(array $paths): void {
    if ($paths === []) {
      return;
    }
    // SQLite's default parameter ceiling is 999, so the IN list is chunked
    // well below it rather than relying on the build's limit.
    foreach (array_chunk(array_values($paths), 500) as $chunk) {
      $placeholders = implode(',', array_fill(0, count($chunk), '?'));
      $statement = $this->pdo->prepare(
        'DELETE FROM ' . self::TABLE . ' WHERE file IN (' . $placeholders . ')'
      );
      $statement->execute(array_values($chunk));
    }
  }

  /**
   * Inserts manifest rows in batches.
   *
   * @param array<int, array{file: string, hash: string, scope: string, type: string}> $rows
   *   The rows.
   * @param int $timestamp
   *   The indexed_at value.
   */
  private function insertBatched(array $rows, int $timestamp): void {
    if ($rows === []) {
      return;
    }
    $statement = $this->pdo->prepare(
      'INSERT INTO ' . self::TABLE . ' (file, hash, scope, type, indexed_at) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($rows as $row) {
      $statement->execute([
        $row['file'],
        $row['hash'],
        $row['scope'],
        $row['type'],
        $timestamp,
      ]);
    }
  }

  /**
   * Runs a unit of work in a transaction, rolling back on any throwable.
   *
   * @param callable():void $work
   *   The work.
   *
   * @throws \Throwable
   *   Whatever the work threw, after the rollback.
   */
  private function transactional(callable $work): void {
    // A nested call would throw on begin; the indexer never nests, but a
    // caller that already opened one keeps ownership of it.
    $owns = !$this->pdo->inTransaction();
    if ($owns) {
      $this->pdo->beginTransaction();
    }
    try {
      $work();
      if ($owns) {
        $this->pdo->commit();
      }
    }
    catch (\Throwable $e) {
      if ($owns && $this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }

  /**
   * Whether the manifest table exists.
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
