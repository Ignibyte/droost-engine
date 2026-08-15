<?php

declare(strict_types=1);

namespace Droost\Engine\Search;

/**
 * An indexable unit of code or documentation.
 *
 * A chunk is what gets embedded and stored: a coherent piece (a PHP symbol, a
 * doc section) with the text to embed and structural metadata returned with
 * search hits.
 */
final readonly class Chunk {

  /**
   * Constructs a Chunk.
   *
   * @param string $corpus
   *   The corpus partition (e.g. "php_symbol", "doc").
   * @param string $ref
   *   A stable identifier within the corpus (e.g. an FQCN or a doc path).
   * @param string $text
   *   The natural-language/code text to embed and lexically search.
   * @param array<string, mixed> $meta
   *   Structural metadata (kind, file, line, module, name, …).
   */
  public function __construct(
    public string $corpus,
    public string $ref,
    public string $text,
    public array $meta = [],
  ) {}

}
