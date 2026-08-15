<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Embedding;

/**
 * No-op embedding backend used when no embedder is configured.
 *
 * Semantic search is unavailable; the lexical/symbol layer still works. This
 * is the graceful-degradation default — droost_search is useful with zero AI
 * configuration.
 */
final class NullEmbeddingBackend implements EmbeddingBackendInterface {

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function embed(array $texts): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function dimension(): int {
    return 0;
  }

}
