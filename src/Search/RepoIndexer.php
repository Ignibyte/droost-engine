<?php

declare(strict_types=1);

namespace Droost\Engine\Search;

use Droost\Engine\Search\Chunker\ChunkerRegistry;
use Droost\Engine\Search\Graph\CodeGraphStorageInterface;
use Droost\Engine\Search\Graph\PhpGraphExtractor;
use Droost\Engine\Search\Graph\YamlGraphExtractor;

/**
 * Indexes a repository with no Drupal site: files in, code graph out.
 *
 * This is NOT the Drupal indexer moved. That one reads extension lists, site
 * configuration and an embedding backend chosen in the database, and indexes
 * the active config corpus — none of which exists in a bare checkout. Trying
 * to make one class serve both would have meant faking half a site.
 *
 * What it does share is everything that was already framework-free: the same
 * chunkers, the same graph visitor, the same IndexDiffer, and storage behind
 * the same ports. So a repo-only index is the same shape as a site-backed one,
 * minus the parts that are genuinely about a running site.
 *
 * Embedding is deliberately absent. Lexical symbol search and the code graph
 * need no model, and they are what makes an unfamiliar checkout navigable;
 * pass the vectors in yourself if you have a backend.
 */
final class RepoIndexer {

  /**
   * The indexer output version; bumping it forces a full rebuild.
   *
   * Kept as its own constant rather than shared with the Drupal indexer: the
   * two write different corpora, and coupling their versions would force
   * pointless rebuilds on one when the other's chunkers change.
   */
  public const int INDEXER_VERSION = 1;

  /**
   * Constructs a RepoIndexer.
   *
   * @param \Droost\Engine\Search\RepoSourceDiscovery $discovery
   *   Finds the files.
   * @param \Droost\Engine\Search\Chunker\ChunkerRegistry $chunkers
   *   Splits file content into chunks.
   * @param \Droost\Engine\Search\Graph\PhpGraphExtractor $php
   *   Extracts symbols and edges from PHP.
   * @param \Droost\Engine\Search\Graph\YamlGraphExtractor $yaml
   *   Extracts service/route edges from YAML.
   * @param \Droost\Engine\Search\FileManifestInterface $manifest
   *   The freshness ledger.
   * @param \Droost\Engine\Search\Graph\CodeGraphStorageInterface $graph
   *   The graph store.
   * @param string $root
   *   The repository root, absolute — used to read file contents.
   */
  public function __construct(
    private readonly RepoSourceDiscovery $discovery,
    private readonly ChunkerRegistry $chunkers,
    private readonly PhpGraphExtractor $php,
    private readonly YamlGraphExtractor $yaml,
    private readonly FileManifestInterface $manifest,
    private readonly CodeGraphStorageInterface $graph,
    private readonly string $root,
  ) {}

  /**
   * Indexes the repository.
   *
   * Incremental by default, on the same rule the Drupal indexer uses: the
   * per-file content hash decides what re-parses and what drops.
   *
   * @param bool $full
   *   Force a full rebuild.
   * @param int|null $timestamp
   *   The indexing timestamp to record; defaults to now. Injectable so a test
   *   can assert the manifest without racing the clock.
   *
   * @return array{files: int, chunks: int, symbols: int, edges: int, mode: string, added: int, changed: int, removed: int, unchanged: int}
   *   Counts: files discovered, chunks produced this run, the post-run graph
   *   totals, the mode that ran, and the four delta counts.
   */
  public function index(bool $full = FALSE, ?int $timestamp = NULL): array {
    $stored = $this->manifest->all();
    $isFull = $full || $stored === [];

    $files = $this->discovery->files();
    $byPath = [];
    $hashes = [];
    foreach ($files as $file) {
      $content = $this->read($file['path']);
      if ($content === NULL) {
        continue;
      }
      $byPath[$file['path']] = $file;
      $hashes[$file['path']] = hash('xxh3', $content);
    }

    $delta = $isFull
      ? new IndexDelta(array_keys($hashes), [], [], 0)
      : IndexDiffer::diff($stored, $hashes, ['repo']);

    $chunks = 0;
    $symbols = [];
    $edges = [];
    foreach ($delta->toParse() as $path) {
      $file = $byPath[$path] ?? NULL;
      if ($file === NULL) {
        continue;
      }
      $content = $this->read($path);
      if ($content === NULL) {
        continue;
      }
      $chunks += count($this->chunkers->chunk($file['type'], $content, $path, $file['module']));
      $extracted = match ($file['type']) {
        'php' => $this->php->extract($content, $path, $file['module']),
        'yaml' => $this->yaml->extract($content, $path, $file['module']),
        default => ['symbols' => [], 'edges' => []],
      };
      foreach ($extracted['symbols'] as $symbol) {
        // Keyed so a file parsed twice in one run cannot double up; the key
        // includes the file, which is what gives per-file identity to the
        // incremental drop.
        $symbols[$symbol['fqcn'] . "\0" . $symbol['kind'] . "\0" . $symbol['file'] . "\0" . $symbol['line']] = $symbol;
      }
      foreach ($extracted['edges'] as $edge) {
        $edge['file'] = $path;
        $edges[$edge['src'] . "\0" . $edge['dst'] . "\0" . $edge['kind'] . "\0" . $path] = $edge;
      }
    }

    if ($isFull) {
      $this->graph->rebuild(array_values($symbols), array_values($edges));
    }
    else {
      $this->graph->applyDelta($delta->toDrop(), array_values($symbols), array_values($edges));
    }

    // The manifest is written LAST, so an interrupted run simply redoes those
    // files next time rather than recording work it did not finish.
    $now = $timestamp ?? time();
    $rows = [];
    foreach ($delta->toParse() as $path) {
      $file = $byPath[$path] ?? NULL;
      if ($file === NULL) {
        continue;
      }
      $rows[] = [
        'file' => $path,
        'hash' => $hashes[$path],
        'scope' => 'repo',
        'type' => $file['type'],
      ];
    }
    if ($isFull) {
      $this->manifest->replaceAll($rows, $now);
    }
    else {
      $this->manifest->apply($rows, $delta->removed, $now);
    }

    $counts = $this->graph->count();
    return [
      'files' => count($files),
      'chunks' => $chunks,
      'symbols' => $counts['symbols'],
      'edges' => $counts['edges'],
      'mode' => $isFull ? 'full' : 'incremental',
      'added' => count($delta->added),
      'changed' => count($delta->changed),
      'removed' => count($delta->removed),
      'unchanged' => $delta->unchanged,
    ];
  }

  /**
   * Reads a file relative to the root.
   *
   * @param string $relative
   *   The relative path.
   *
   * @return string|null
   *   The contents, or NULL when unreadable.
   */
  private function read(string $relative): ?string {
    $path = $this->root . '/' . $relative;
    if (!is_file($path) || !is_readable($path)) {
      return NULL;
    }
    $content = @file_get_contents($path);
    return $content === FALSE ? NULL : $content;
  }

}
