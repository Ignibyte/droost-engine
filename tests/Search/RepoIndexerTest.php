<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Search;

use Droost\Engine\Search\Chunker\ChunkerRegistry;
use Droost\Engine\Search\Chunker\DocChunker;
use Droost\Engine\Search\Chunker\PhpChunker;
use Droost\Engine\Search\Chunker\TwigChunker;
use Droost\Engine\Search\Graph\PhpGraphExtractor;
use Droost\Engine\Search\Graph\SqliteCodeGraphStorage;
use Droost\Engine\Search\Graph\YamlGraphExtractor;
use Droost\Engine\Search\RepoIndexer;
use Droost\Engine\Search\RepoSourceDiscovery;
use Droost\Engine\Search\Storage\SqliteFileManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the repo-only indexer.
 *
 * The claim under test is the one B5b exists to make: a repository with no
 * Drupal site behind it can be indexed and then queried. So these build a
 * small tree on disk, index it, and ask the resulting graph real questions.
 */
#[CoversClass(RepoIndexer::class)]
#[CoversClass(RepoSourceDiscovery::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class RepoIndexerTest extends TestCase {

  /**
   * The temporary repository root.
   */
  private string $root;

  /**
   * The graph the indexer writes.
   */
  private SqliteCodeGraphStorage $graph;

  /**
   * The manifest the indexer writes.
   */
  private SqliteFileManifest $manifest;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/droost_repo_' . uniqid();
    mkdir($this->root . '/modules/my_module/src', 0777, TRUE);
    mkdir($this->root . '/vendor/evil', 0777, TRUE);

    file_put_contents($this->root . '/modules/my_module/my_module.info.yml', "name: My Module\ntype: module\n");
    file_put_contents(
      $this->root . '/modules/my_module/src/Greeter.php',
      "<?php\n\nnamespace Drupal\\my_module;\n\nclass Greeter extends Base implements Speaks {\n  public function hi(): string { return 'hi'; }\n}\n",
    );
    file_put_contents(
      $this->root . '/modules/my_module/src/Base.php',
      "<?php\n\nnamespace Drupal\\my_module;\n\nclass Base {}\n",
    );
    file_put_contents($this->root . '/modules/my_module/README.md', "# My Module\n\nIt greets.\n");
    // Must never be indexed: vendor is skipped, so this class stays out.
    file_put_contents(
      $this->root . '/vendor/evil/Sneaky.php',
      "<?php\n\nnamespace Vendor\\Evil;\n\nclass Sneaky {}\n",
    );

    $this->graph = SqliteCodeGraphStorage::open(':memory:');
    $this->manifest = SqliteFileManifest::open(':memory:');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    self::rrmdir($this->root);
    parent::tearDown();
  }

  /**
   * A bare checkout indexes, and the graph then answers real questions.
   */
  public function testIndexesRepositoryWithoutSite(): void {
    $report = $this->indexer()->index();

    $this->assertSame('full', $report['mode']);
    // info.yml is not an indexable type; the two PHP files and the README are.
    $this->assertSame(3, $report['files']);
    $this->assertGreaterThan(0, $report['chunks']);
    $this->assertGreaterThan(0, $report['symbols']);

    // The questions droost_graph would ask, answered with no site at all.
    $this->assertSame(
      ['Drupal\\my_module\\Greeter'],
      $this->graph->resolveShortName('Greeter'),
    );
    $edges = $this->graph->dependencies('Drupal\\my_module\\Greeter');
    $kinds = [];
    foreach ($edges as $edge) {
      $kind = is_string($edge['kind'] ?? NULL) ? $edge['kind'] : '';
      $kinds[$kind][] = $edge['dst'] ?? NULL;
    }
    $this->assertContains('Drupal\\my_module\\Base', $kinds['extends'] ?? []);
    // Fully qualified: the NameResolver pass resolves a bare "Speaks" against
    // the file's namespace, which is exactly why the graph is keyed on FQCNs.
    $this->assertContains('Drupal\\my_module\\Speaks', $kinds['implements'] ?? []);
  }

  /**
   * The extension name is inferred from the info.yml that owns the directory.
   */
  public function testAttributesSymbolsToTheirExtension(): void {
    $this->indexer()->index();
    $symbols = $this->graph->findSymbols('Greeter', 10);
    $this->assertNotSame([], $symbols);
    $this->assertSame('my_module', $symbols[0]['module']);
  }

  /**
   * The vendor directory is never indexed.
   */
  public function testSkipsVendor(): void {
    $this->indexer()->index();
    $this->assertSame([], $this->graph->findSymbols('Sneaky', 10));
  }

  /**
   * A second run with nothing changed re-parses nothing.
   */
  public function testSecondRunIsIncrementalAndQuiet(): void {
    $this->indexer()->index();
    $second = $this->indexer()->index();

    $this->assertSame('incremental', $second['mode']);
    $this->assertSame(0, $second['added']);
    $this->assertSame(0, $second['changed']);
    $this->assertSame(3, $second['unchanged']);
  }

  /**
   * A changed file re-parses; a deleted one drops out of the graph.
   */
  public function testIncrementalPicksUpChangesAndDeletions(): void {
    $this->indexer()->index();

    file_put_contents(
      $this->root . '/modules/my_module/src/Greeter.php',
      "<?php\n\nnamespace Drupal\\my_module;\n\nclass Greeter {}\n",
    );
    $changed = $this->indexer()->index();
    $this->assertSame(1, $changed['changed']);
    // The extends edge is gone, because the file's rows were dropped and
    // replaced rather than merged on top of the old ones.
    $this->assertSame([], $this->graph->dependencies('Drupal\\my_module\\Greeter'));

    unlink($this->root . '/modules/my_module/src/Base.php');
    $removed = $this->indexer()->index();
    $this->assertSame(1, $removed['removed']);
    $this->assertSame([], $this->graph->findSymbols('my_module\\Base', 10));
  }

  /**
   * Discovery is ordered, so two runs over an unchanged tree agree.
   */
  public function testDiscoveryIsDeterministic(): void {
    $discovery = new RepoSourceDiscovery($this->root);
    $this->assertSame($discovery->files(), $discovery->files());
    $paths = array_column($discovery->files(), 'path');
    $sorted = $paths;
    sort($sorted);
    $this->assertSame($sorted, $paths);
  }

  /**
   * Builds an indexer over the temporary tree.
   *
   * @return \Droost\Engine\Search\RepoIndexer
   *   The indexer.
   */
  private function indexer(): RepoIndexer {
    return new RepoIndexer(
      new RepoSourceDiscovery($this->root),
      new ChunkerRegistry(new PhpChunker(), new DocChunker(), new TwigChunker()),
      new PhpGraphExtractor(),
      new YamlGraphExtractor(),
      $this->manifest,
      $this->graph,
      $this->root,
    );
  }

  /**
   * Removes a directory tree.
   *
   * @param string $dir
   *   The directory.
   */
  private static function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    foreach (scandir($dir) ?: [] as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }
      $path = $dir . '/' . $item;
      if (is_dir($path)) {
        self::rrmdir($path);
      }
      else {
        unlink($path);
      }
    }
    rmdir($dir);
  }

}
