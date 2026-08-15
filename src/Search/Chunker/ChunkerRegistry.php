<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Chunker;

/**
 * Routes a file to the chunker for its type.
 *
 * Keeps the {@see \Droost\Engine\Search\Indexer} free of per-type branching and
 * of one constructor argument per chunker.
 */
final readonly class ChunkerRegistry {

  /**
   * Constructs a ChunkerRegistry.
   *
   * @param \Droost\Engine\Search\Chunker\PhpChunker $phpChunker
   *   The PHP symbol chunker.
   * @param \Droost\Engine\Search\Chunker\DocChunker $docChunker
   *   The documentation chunker.
   * @param \Droost\Engine\Search\Chunker\TwigChunker $twigChunker
   *   The Twig template chunker.
   */
  public function __construct(
    private PhpChunker $phpChunker,
    private DocChunker $docChunker,
    private TwigChunker $twigChunker,
  ) {}

  /**
   * Chunks file content according to its index type.
   *
   * @param string $type
   *   The index type: php|twig|doc.
   * @param string $content
   *   The file content.
   * @param string $file
   *   The app-root-relative file path.
   * @param string $module
   *   The owning extension name.
   *
   * @return array<int, \Droost\Engine\Search\Chunk>
   *   The produced chunks.
   */
  public function chunk(string $type, string $content, string $file, string $module): array {
    return match ($type) {
      'php' => $this->phpChunker->chunk($content, $file, $module),
      'twig' => $this->twigChunker->chunk($content, $file, $module),
      default => $this->docChunker->chunk($content, $file, $module),
    };
  }

}
