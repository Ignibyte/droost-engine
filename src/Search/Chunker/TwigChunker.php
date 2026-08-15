<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Chunker;

use Droost\Engine\Search\Chunk;

/**
 * Chunks a Twig template as a single unit.
 *
 * Templates are small and cohesive, so one chunk per file (capped) is a good
 * semantic unit for "which template renders X" queries.
 */
final class TwigChunker {

  /**
   * Maximum characters embedded from a template.
   */
  private const int MAX_CHARS = 4000;

  /**
   * Chunks a Twig template.
   *
   * @param string $template
   *   The template source.
   * @param string $file
   *   The file path (relative to the app root).
   * @param string $module
   *   The owning extension (theme/module).
   *
   * @return array<int, \Droost\Engine\Search\Chunk>
   *   A single chunk, or none for an empty template.
   */
  public function chunk(string $template, string $file, string $module): array {
    $body = trim($template);
    if ($body === '') {
      return [];
    }
    $name = basename($file);
    $text = $name . "\n" . mb_substr($body, 0, self::MAX_CHARS);
    return [new Chunk('twig', $file, $text, ['file' => $file, 'module' => $module, 'template' => $name])];
  }

}
