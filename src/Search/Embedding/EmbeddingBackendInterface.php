<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Embedding;

/**
 * Turns text into embedding vectors.
 *
 * Implementations may call a local model server (Ollama / OpenAI-compatible),
 * the Drupal AI module, or nothing (the null backend, when no embedding
 * service is configured — semantic search is then disabled and only the
 * lexical/symbol layer works).
 */
interface EmbeddingBackendInterface {

  /**
   * Whether the backend is configured and usable.
   *
   * @return bool
   *   TRUE if embed() can produce vectors.
   */
  public function isAvailable(): bool;

  /**
   * Embeds a batch of texts.
   *
   * @param array<int, string> $texts
   *   The texts to embed.
   *
   * @return array<int, array<int, float>>
   *   One vector per input text, in the same order. Empty when the backend is
   *   unavailable.
   */
  public function embed(array $texts): array;

  /**
   * The dimension of the vectors this backend produces.
   *
   * @return int
   *   The embedding dimension (0 when unavailable/unknown).
   */
  public function dimension(): int;

}
