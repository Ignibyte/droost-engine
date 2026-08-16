<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Graph;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parses PHP source into graph symbols and edges.
 *
 * The Drupal extractor differs from this one in exactly one respect: it
 * discovers hook names by scanning the site's api.php files. That is a list of
 * strings, so it is a constructor argument here rather than a service —
 * which is what makes the same extraction available with no site.
 */
final class PhpGraphExtractor {

  /**
   * Constructs a PhpGraphExtractor.
   *
   * @param array<string, true> $hookNames
   *   Known hook names as a set keyed by name — the shape GraphVisitor looks
   *   them up in. An empty set is valid: classes, interfaces and call edges
   *   are still extracted, and only hook edges are missed, which is exactly
   *   the repo-only case (no site to scan api.php files from).
   */
  public function __construct(
    private readonly array $hookNames = [],
  ) {}

  /**
   * Parses PHP source and returns its symbols and edges.
   *
   * @param string $code
   *   The PHP source.
   * @param string $file
   *   The file path, for metadata.
   * @param string $module
   *   The owning extension.
   *
   * @return array{symbols: array<int, array{fqcn: string, kind: string, file: string, line: int, module: string}>, edges: array<int, array{src: string, dst: string, kind: string}>}
   *   The collected symbols and edges (empty on parse failure).
   */
  public function extract(string $code, string $file, string $module): array {
    // Strip a UTF-8 BOM so a namespaced file is not misparsed as inline HTML
    // (which throws and would silently drop the file from the graph).
    if (str_starts_with($code, "\xEF\xBB\xBF")) {
      $code = substr($code, 3);
    }
    $parser = (new ParserFactory())->createForHostVersion();
    try {
      $ast = $parser->parse($code);
    }
    catch (\Throwable) {
      // A file that does not parse is skipped, not fatal: one broken file in
      // a tree of thousands must not cost the whole index.
      return ['symbols' => [], 'edges' => []];
    }
    if ($ast === NULL) {
      return ['symbols' => [], 'edges' => []];
    }
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver());
    $visitor = new GraphVisitor($file, $module, $this->hookNames);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);
    return ['symbols' => $visitor->symbols, 'edges' => $visitor->edges];
  }

}
