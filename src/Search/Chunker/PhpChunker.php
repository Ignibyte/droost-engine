<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Chunker;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Chunks PHP source into symbol chunks via nikic/php-parser.
 *
 * One chunk per class/interface/trait/enum/function/method, carrying the FQCN,
 * kind, location, and cleaned docblock — AST-accurate boundaries and metadata,
 * which beat generic line-window chunking for both retrieval and the code
 * graph that builds on the same symbols.
 */
final class PhpChunker {

  /**
   * Extracts symbol chunks from PHP source.
   *
   * @param string $code
   *   The PHP source.
   * @param string $file
   *   The file path (relative to the app root), for metadata.
   * @param string $module
   *   The owning extension machine name.
   *
   * @return array<int, \Droost\Engine\Search\Chunk>
   *   The symbol chunks (empty if the source cannot be parsed).
   */
  public function chunk(string $code, string $file, string $module): array {
    // Strip a UTF-8 BOM: with one present, a namespaced file is parsed as
    // inline HTML and php-parser throws, silently dropping the whole file.
    if (str_starts_with($code, "\xEF\xBB\xBF")) {
      $code = substr($code, 3);
    }
    $parser = (new ParserFactory())->createForHostVersion();
    try {
      $ast = $parser->parse($code);
    }
    catch (\Throwable) {
      return [];
    }
    if ($ast === NULL) {
      return [];
    }
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver());
    $collector = new SymbolCollector($file, $module);
    $traverser->addVisitor($collector);
    $traverser->traverse($ast);
    return $collector->chunks;
  }

}
