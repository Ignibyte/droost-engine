<?php

declare(strict_types=1);

namespace Droost\Engine\Wiki;

/**
 * Content hashing for wiki provenance manifests.
 *
 * The one place the hash algorithm lives: pages record
 * "<algo>:<lowercase hex>" over the exact on-disk bytes (no end-of-line or
 * encoding normalization), so producer and checker agree as long as they run
 * on the same checkout. The algo prefix keeps the field self-describing so
 * the algorithm can rotate without a contract version bump.
 */
final readonly class FileHasher {

  private const string ALGO = 'xxh3';

  /**
   * Hashes a file's exact on-disk bytes.
   *
   * @param string $absolute_path
   *   The absolute path to hash.
   *
   * @return string|null
   *   The prefixed hash ("xxh3:9f86…"), or NULL when the file is missing or
   *   unreadable — callers decide the failure posture; the status checker
   *   treats unreadable-but-existing as stale (failing closed).
   */
  public function hash(string $absolute_path): ?string {
    if (!is_file($absolute_path) || !is_readable($absolute_path)) {
      return NULL;
    }
    $hash = @hash_file(self::ALGO, $absolute_path);
    return is_string($hash) ? self::ALGO . ':' . $hash : NULL;
  }

  /**
   * The current hash algorithm.
   *
   * @return string
   *   The algo name used as the hash prefix.
   */
  public function algo(): string {
    return self::ALGO;
  }

}
