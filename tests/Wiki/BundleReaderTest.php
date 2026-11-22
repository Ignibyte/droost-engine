<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Wiki;

use Droost\Engine\Wiki\BundleLocatorInterface;
use Droost\Engine\Wiki\BundleReader;
use Droost\Engine\Wiki\Okf\FrontmatterParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A page is read past its frontmatter, however long that is.
 */
#[CoversClass(BundleReader::class)]
final class BundleReaderTest extends TestCase {

  /**
   * The bundle directory.
   */
  private string $bundle;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->bundle = sys_get_temp_dir() . '/droost-bundle-' . bin2hex(random_bytes(6));
    mkdir($this->bundle, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    exec('rm -rf ' . escapeshellarg($this->bundle));
    parent::tearDown();
  }

  /**
   * The display cap is on the body, not on the file (F-503).
   *
   * A page's sources are listed in its frontmatter, one entry each, and a
   * page over a large module has hundreds. The first 16,000 bytes of the
   * file were read for display and search: with 23 kB of frontmatter the
   * fence never closed inside them, so the page had no title and its body
   * could not be found.
   */
  public function testLongFrontmatterDoesNotHideTheBody(): void {
    $sources = '';
    for ($i = 0; $i < 400; $i++) {
      $sources .= sprintf("    - path: web/modules/custom/big/src/File%04d.php\n      hash: \"xxh3:9f86d081884c7d65\"\n", $i);
    }
    $this->assertGreaterThan(20000, strlen($sources));
    file_put_contents(
      $this->bundle . '/big.md',
      "---\ntitle: \"big\"\ndroost:\n  spec: 1\n  modules: [big]\n  sources:\n" . $sources . "---\n\n# big\n\nThe needle is in the body.\n",
    );
    $reader = new BundleReader($this->locator(), new FrontmatterParser());

    $page = $reader->read('big.md');
    $this->assertNotNull($page);
    $this->assertSame('big', $page['meta']['title']);
    $this->assertStringContainsString('The needle is in the body.', $page['content']);

    $found = $reader->search('needle', 5, 3);
    $this->assertNotSame([], $found, 'the body is searched');
  }

  /**
   * A locator for the scratch bundle.
   *
   * @return \Droost\Engine\Wiki\BundleLocatorInterface
   *   The locator.
   */
  private function locator(): BundleLocatorInterface {
    return new class($this->bundle) implements BundleLocatorInterface {

      /**
       * Constructs the locator.
       *
       * @param string $path
       *   The bundle directory.
       */
      public function __construct(private readonly string $path) {}

      /**
       * {@inheritdoc}
       */
      public function bundlePath(): string {
        return $this->path;
      }

    };
  }

}
