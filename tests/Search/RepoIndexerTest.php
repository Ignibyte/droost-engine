<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Search;

use Droost\Engine\Search\FileManifestInterface;
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

    $this->graph = new SqliteCodeGraphStorage(new \PDO('sqlite::memory:'));
    $this->manifest = new SqliteFileManifest(new \PDO('sqlite::memory:'));
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
   * A file whose extension appears later is given to it (F-502).
   *
   * What decides a re-parse was the file's content alone, and the extension
   * a file belongs to is not in its content: a class indexed before its
   * module had an info file kept module `` until a full rebuild.
   */
  public function testFileIsGivenToAnExtensionThatAppearsLater(): void {
    mkdir($this->root . '/modules/late/src', 0777, TRUE);
    file_put_contents($this->root . '/modules/late/src/Thing.php', "<?php\n\nnamespace Drupal\\late;\n\nclass Thing {}\n");
    $this->indexer()->index();
    $this->assertSame('', $this->graph->findSymbols('Thing', 10)[0]['module']);

    file_put_contents($this->root . '/modules/late/late.info.yml', "name: Late\ntype: module\n");
    $report = $this->indexer()->index();

    $this->assertSame('incremental', $report['mode']);
    $things = $this->graph->findSymbols('Thing', 10);
    $this->assertCount(1, $things);
    $this->assertSame('late', $things[0]['module']);
  }

  /**
   * A run that died before its manifest was written leaves no doubles.
   *
   * The graph is written first and the manifest last, so a run killed in
   * between is redone. The redo saw its new files as new again and added
   * their rows a second time: only changed and removed files were dropped
   * first (F-502).
   */
  public function testRedoAfterInterruptedRunAddsNothingTwice(): void {
    $this->indexer()->index();
    file_put_contents($this->root . '/modules/my_module/src/Added.php', "<?php\n\nnamespace Drupal\\my_module;\n\nclass Added {}\n");

    $dying = new class($this->manifest) implements FileManifestInterface {

      /**
       * Constructs the manifest that dies.
       *
       * @param \Droost\Engine\Search\FileManifestInterface $inner
       *   The real one.
       */
      public function __construct(private readonly FileManifestInterface $inner) {}

      /**
       * {@inheritdoc}
       */
      public function ensureSchema(): void {
        $this->inner->ensureSchema();
      }

      /**
       * {@inheritdoc}
       */
      public function all(): array {
        return $this->inner->all();
      }

      /**
       * {@inheritdoc}
       */
      public function apply(array $upserts, array $removals, int $timestamp): void {
        throw new \RuntimeException('killed before the manifest was written');
      }

      /**
       * {@inheritdoc}
       */
      public function replaceAll(array $rows, int $timestamp): void {
        $this->inner->replaceAll($rows, $timestamp);
      }

    };
    try {
      (new RepoIndexer(new RepoSourceDiscovery($this->root), new PhpGraphExtractor(), new YamlGraphExtractor(), $dying, $this->graph, $this->root))->index();
      $this->fail('the run was meant to die');
    }
    catch (\RuntimeException) {
      // The graph holds the new file's rows; the manifest does not know it.
    }
    $this->indexer()->index();

    $this->assertCount(1, $this->graph->findSymbols('Added', 10), 'the redo replaced the rows; it did not add them again');
  }

  /**
   * A hook an api.php documents is a symbol in the index (F-498).
   *
   * The extractor declares `hook:NAME` for a function in an `.api.php`
   * (indexer v3), and the indexer never handed it one: discovery types the
   * file `doc`, and only `php` and `yaml` were extracted. Every hook edge
   * then pointed at a symbol that did not exist, and a module reached only
   * through its hooks read as reached by nothing.
   */
  public function testHookAnApiFileDocumentsIsSymbol(): void {
    file_put_contents(
      $this->root . '/modules/my_module/my_module.api.php',
      "<?php\n\n/**\n * Alters a greeting.\n */\nfunction hook_my_module_greeting_alter(array &\$greeting): void {\n}\n",
    );
    $this->indexer()->index();

    $hooks = $this->graph->findSymbols('hook:my_module_greeting_alter', 10);
    $this->assertCount(1, $hooks);
    $this->assertSame('my_module', $hooks[0]['module']);
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
