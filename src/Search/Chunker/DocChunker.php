<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Chunker;

use Droost\Engine\Search\Chunk;

/**
 * Chunks Markdown documentation by heading section.
 *
 * Each chunk is a heading plus the body up to the next heading — coherent units
 * for semantic search over READMEs, *.md, and *.api.php narrative docs.
 */
final class DocChunker {

  /**
   * Splits Markdown into per-heading chunks.
   *
   * @param string $markdown
   *   The Markdown source.
   * @param string $file
   *   The file path (relative to the app root), for metadata and the ref.
   * @param string $module
   *   The owning extension machine name.
   *
   * @return array<int, \Droost\Engine\Search\Chunk>
   *   The doc chunks.
   */
  public function chunk(string $markdown, string $file, string $module): array {
    $chunks = [];
    $heading = '';
    $body = '';
    $index = 0;
    $flush = function () use (&$chunks, &$heading, &$body, &$index, $file, $module): void {
      $text = trim($heading . "\n" . $body);
      if ($text !== '') {
        $chunks[] = new Chunk('doc', $file . '#' . $index++, $text, [
          'file' => $file,
          'module' => $module,
          'heading' => $heading,
        ]);
      }
      $body = '';
    };

    $inFence = FALSE;
    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
      // Track fenced code blocks so a "#"-prefixed code line (a PHP/shell
      // comment) is not mistaken for a heading and does not split the chunk.
      if (preg_match('/^\s*(`{3,}|~{3,})/', $line) === 1) {
        $inFence = !$inFence;
        $body .= $line . "\n";
        continue;
      }
      if (!$inFence && preg_match('/^#{1,6}\s+(.*)$/', $line, $m) === 1) {
        $flush();
        $heading = trim($m[1]);
      }
      else {
        $body .= $line . "\n";
      }
    }
    $flush();
    return $chunks;
  }

}
