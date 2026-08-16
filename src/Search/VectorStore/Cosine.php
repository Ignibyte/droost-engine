<?php

declare(strict_types=1);

namespace Droost\Engine\Search\VectorStore;

/**
 * Cosine similarity, shared by every store that scores in PHP.
 *
 * Ranking is the one thing two vector stores must never disagree about: a
 * different score is a different result list, and nothing about the output
 * would reveal which store produced it. So the arithmetic lives here once
 * rather than being reimplemented per backend.
 */
final class Cosine {

  /**
   * Cosine similarity of two vectors, in [0, 1].
   *
   * Returns 0.0 rather than throwing on degenerate input — a zero-magnitude
   * or dimension-mismatched vector is unrankable, and 0.0 puts it last
   * instead of aborting a search over thousands of good rows.
   *
   * @param array<array-key, mixed> $a
   *   First vector.
   * @param array<array-key, mixed> $b
   *   Second vector.
   *
   * @return float
   *   Cosine similarity, clamped to [0, 1]; 0 when a magnitude is zero or the
   *   dimensions differ.
   */
  public static function similarity(array $a, array $b): float {
    if (count($a) !== count($b) || $a === []) {
      return 0.0;
    }
    $dot = 0.0;
    $na = 0.0;
    $nb = 0.0;
    foreach ($a as $i => $rawA) {
      $va = is_numeric($rawA) ? (float) $rawA : 0.0;
      $rawB = $b[$i] ?? NULL;
      $vb = is_numeric($rawB) ? (float) $rawB : 0.0;
      $dot += $va * $vb;
      $na += $va * $va;
      $nb += $vb * $vb;
    }
    if ($na <= 0.0 || $nb <= 0.0) {
      return 0.0;
    }
    return max(0.0, min(1.0, $dot / (sqrt($na) * sqrt($nb))));
  }

}
