<?php

declare(strict_types=1);

namespace Droost\Engine\Search\VectorStore;

/**
 * Stores and KNN-searches embedding vectors for indexed code/doc chunks.
 *
 * Implementations may back onto native database vectors, a portable PHP
 * implementation, or a Drupal AI vector-database provider. A chunk is
 * identified by ("corpus", "ref"): the corpus partitions the index
 * (e.g. "php_symbol", "doc"), and ref is a stable id within it (e.g. an FQCN
 * or a file path).
 */
interface VectorStoreInterface {

  /**
   * Whether this store can run on the current environment.
   *
   * @return bool
   *   TRUE if the store's backing technology is available.
   */
  public function isAvailable(): bool;

  /**
   * Ensures the backing storage exists for the given vector dimension.
   *
   * Idempotent. If a store was created for a different dimension it is reset,
   * since vectors of mixed dimension cannot be compared.
   *
   * @param int $dimension
   *   The embedding dimension every stored vector will have.
   */
  public function ensureSchema(int $dimension): void;

  /**
   * Inserts or replaces a chunk's vector.
   *
   * @param string $corpus
   *   The corpus partition.
   * @param string $ref
   *   The chunk identifier within the corpus.
   * @param array<int, float> $vector
   *   The embedding, with exactly the configured dimension.
   * @param array<string, mixed> $meta
   *   Arbitrary metadata stored alongside (returned with search hits).
   */
  public function upsert(string $corpus, string $ref, array $vector, array $meta = []): void;

  /**
   * Atomically replaces the entire store with the given vectors.
   *
   * Ensures the schema, then clears and repopulates inside a transaction so a
   * concurrent search sees either the complete previous set or the complete new
   * set — never a half-rebuilt store. Rows are inserted in batches. Each row
   * may carry a "file" (the app-root-relative source path) for per-file
   * incremental deletion; rows without one (e.g. config chunks) store ''.
   *
   * @param array<int, array{corpus: string, ref: string, vector: array<int, float>, meta: array<string, mixed>, file?: string}> $rows
   *   The vectors to store (full replacement).
   * @param int $dimension
   *   The embedding dimension every vector has.
   */
  public function replace(array $rows, int $dimension): void;

  /**
   * Inserts-or-replaces a batch of rows without clearing the store.
   *
   * The incremental-indexing write: rows for re-parsed files land over
   * whatever was there (keyed by corpus+ref), everything else is untouched.
   *
   * @param array<int, array{corpus: string, ref: string, vector: array<int, float>, meta: array<string, mixed>, file?: string}> $rows
   *   The vectors to upsert.
   * @param int $dimension
   *   The embedding dimension every vector has.
   */
  public function upsertBatch(array $rows, int $dimension): void;

  /**
   * Deletes every vector derived from a source file.
   *
   * @param string $file
   *   The app-root-relative source path.
   */
  public function deleteByFile(string $file): void;

  /**
   * Returns the nearest stored vectors to a query vector (cosine).
   *
   * @param array<int, float> $vector
   *   The query embedding.
   * @param int $k
   *   Maximum hits to return.
   * @param string|null $corpus
   *   Restrict to this corpus, or NULL for all.
   * @param array<string, scalar> $metaFilter
   *   Equality filters on stored meta keys (e.g. ['scope' => 'custom']). A row
   *   matches when every named key is present and equal. Implementations MUST
   *   apply the filter before the $k limit — filtering a k-limited set
   *   silently under-returns — and may reject keys that are not simple
   *   identifiers ([A-Za-z0-9_]+). Stores that filter in the database compare
   *   values as strings, so filter on string meta keys for portable results.
   *
   * @return array<int, array{corpus: string, ref: string, score: float, meta: array<mixed, mixed>}>
   *   Hits ordered by descending similarity (score in [0,1], 1 = identical).
   */
  public function search(array $vector, int $k = 10, ?string $corpus = NULL, array $metaFilter = []): array;

  /**
   * Deletes stored vectors.
   *
   * @param string|null $corpus
   *   Restrict to this corpus, or NULL for all corpora.
   * @param string|null $ref
   *   Restrict to this ref (requires $corpus), or NULL for all in the corpus.
   */
  public function delete(?string $corpus = NULL, ?string $ref = NULL): void;

  /**
   * Returns the number of stored vectors.
   *
   * @return int
   *   The count.
   */
  public function count(): int;

  /**
   * The dimension of the vectors currently stored, or 0 if none/unknown.
   *
   * Lets a caller detect query/stored dimension drift (e.g. after the embedding
   * model changed without a reindex) before searching, rather than scoring
   * 0.0-noise or triggering a native VECTOR error.
   *
   * @return int
   *   The stored vector dimension, or 0 when empty/undetermined.
   */
  public function dimension(): int;

}
