<?php

declare(strict_types=1);

namespace Droost\Engine\Search;

/**
 * The per-file index manifest: which file was indexed at which content hash.
 *
 * The freshness ledger incremental indexing keys on. One row per indexed file:
 * the root-relative path, its content hash, the scope and type discovery
 * assigned, and when it was indexed.
 *
 * This is a PORT, not a lift. The Drupal-backed implementation is built on
 * Drupal's database API and belongs in the module; this interface exists so
 * the indexer can also run against a plain PDO/SQLite store with no site at
 * all — see SqliteFileManifest.
 */
interface FileManifestInterface {

  /**
   * Ensures the manifest storage exists.
   *
   * Called before any write. Reads tolerate its absence and return empty,
   * because "never indexed" and "indexed nothing" are the same answer to a
   * caller and neither is an error.
   */
  public function ensureSchema(): void;

  /**
   * Loads the whole manifest.
   *
   * @return array<string, array{hash: string, scope: string}>
   *   Rows keyed by path (empty before the first indexed run).
   */
  public function all(): array;

  /**
   * Atomically replaces the whole manifest (the full-rebuild path).
   *
   * @param array<int, array{file: string, hash: string, scope: string, type: string}> $rows
   *   The new manifest rows.
   * @param int $timestamp
   *   The indexing timestamp recorded on every row.
   */
  public function replaceAll(array $rows, int $timestamp): void;

  /**
   * Applies an incremental update: upserts + removals in one transaction.
   *
   * @param array<int, array{file: string, hash: string, scope: string, type: string}> $upserts
   *   Rows for added/changed files.
   * @param array<int, string> $removals
   *   Paths whose rows are removed.
   * @param int $timestamp
   *   The indexing timestamp recorded on upserted rows.
   */
  public function apply(array $upserts, array $removals, int $timestamp): void;

}
