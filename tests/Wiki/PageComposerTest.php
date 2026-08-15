<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Wiki;

use Droost\Engine\Wiki\ComposeException;
use Droost\Engine\Wiki\Okf\FrontmatterParser;
use Droost\Engine\Wiki\PageComposer;
use PHPUnit\Framework\TestCase;

/**
 * Covers PageComposer — the deterministic, verified-by-construction author.
 *
 * Pure: the composer + parser need no container, so the whole compose →
 * validate contract is exercised here with hand-built factsheets.
 */
final class PageComposerTest extends TestCase {

  /**
   * The composer under test.
   */
  private PageComposer $composer;

  /**
   * The parser used to read composed pages back.
   */
  private FrontmatterParser $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->parser = new FrontmatterParser();
    $this->composer = new PageComposer($this->parser);
  }

  /**
   * A minimal valid factsheet for module "foo".
   *
   * @param array<int, array{path: string, hash: string}> $sources
   *   The provenance_template sources.
   *
   * @return array<string, mixed>
   *   The factsheet packet.
   */
  private function factsheet(array $sources): array {
    return [
      'module' => 'foo',
      'identity' => ['label' => 'Foo', 'description' => 'The Foo module.'],
      'docs' => ['primary' => 'foo/README.md'],
      'provenance_template' => [
        'spec' => 1,
        'modules' => ['foo'],
        'sources' => $sources,
        'generated_commit' => '(fill in: git rev-parse --short HEAD)',
        'generator' => '(your agent identity)',
        'queries' => ['droost_wiki_factsheet'],
      ],
    ];
  }

  /**
   * The composer authors every provenance fact deterministically.
   */
  public function testAuthorsProvenanceDeterministically(): void {
    $factsheet = $this->factsheet([
      ['path' => 'foo/foo.info.yml', 'hash' => 'xxh3:1111111111111111'],
      ['path' => 'foo/src/Foo.php', 'hash' => 'xxh3:2222222222222222'],
    ]);
    $page = $this->composer->compose('foo', $factsheet, "# Foo\n\nProse.\n", 'abc1234', '2026-07-27T00:00:00Z');

    $meta = $this->parser->parse($page);
    $this->assertTrue($meta->isManaged());
    $this->assertFalse($meta->isInvalid());
    $this->assertNotNull($meta->provenance);
    $this->assertSame(1, $meta->provenance->spec);
    $this->assertSame(['foo'], $meta->provenance->modules);
    // The driver's commit + generator win — never the template placeholders.
    $this->assertSame('abc1234', $meta->provenance->generatedCommit);
    $this->assertSame(PageComposer::GENERATOR, $meta->provenance->generator);
    $this->assertStringContainsString('# Foo', $meta->body);
  }

  /**
   * Any frontmatter the body carried is stripped; the composer owns metadata.
   */
  public function testStripsBodyFrontmatter(): void {
    $factsheet = $this->factsheet([
      ['path' => 'foo/foo.info.yml', 'hash' => 'xxh3:1111111111111111'],
    ]);
    $body = "---\ntitle: EVIL\ndroost:\n  spec: 99\n---\n\n# Real body\n";
    $page = $this->composer->compose('foo', $factsheet, $body, '', NULL);

    $meta = $this->parser->parse($page);
    $this->assertNotNull($meta->provenance);
    // The composer's spec (1) survives; the body's forged spec (99) is gone.
    $this->assertSame(1, $meta->provenance->spec);
    $this->assertStringNotContainsString('EVIL', $page);
    $this->assertStringNotContainsString('spec: 99', $page);
    $this->assertStringContainsString('# Real body', $meta->body);
  }

  /**
   * Sources are identity-first and capped at MAX_SOURCES.
   */
  public function testSelectsIdentityFirstAndCaps(): void {
    $factsheet = $this->factsheet([
      ['path' => 'foo/src/A.php', 'hash' => 'xxh3:aaaaaaaaaaaaaaaa'],
      ['path' => 'foo/src/B.php', 'hash' => 'xxh3:bbbbbbbbbbbbbbbb'],
      ['path' => 'foo/src/C.php', 'hash' => 'xxh3:cccccccccccccccc'],
      ['path' => 'foo/src/D.php', 'hash' => 'xxh3:dddddddddddddddd'],
      ['path' => 'foo/foo.info.yml', 'hash' => 'xxh3:1111111111111111'],
      ['path' => 'foo/foo.services.yml', 'hash' => 'xxh3:2222222222222222'],
      ['path' => 'foo/src/E.php', 'hash' => 'xxh3:eeeeeeeeeeeeeeee'],
      ['path' => 'foo/src/F.php', 'hash' => 'xxh3:ffffffffffffffff'],
    ]);
    $meta = $this->parser->parse($this->composer->compose('foo', $factsheet, "# Foo\n", '', NULL));

    $this->assertNotNull($meta->provenance);
    $paths = array_column($meta->provenance->sources, 'path');
    $this->assertCount(PageComposer::MAX_SOURCES, $paths);
    // Identity files are kept regardless of their position in the inventory.
    $this->assertContains('foo/foo.info.yml', $paths);
    $this->assertContains('foo/foo.services.yml', $paths);
  }

  /**
   * An empty timestamp/commit still composes a valid page.
   */
  public function testEmptyCommitIsValid(): void {
    $factsheet = $this->factsheet([
      ['path' => 'foo/foo.info.yml', 'hash' => 'xxh3:1111111111111111'],
    ]);
    $meta = $this->parser->parse($this->composer->compose('foo', $factsheet, "# Foo\n", '', NULL));
    $this->assertNotNull($meta->provenance);
    $this->assertSame('', $meta->provenance->generatedCommit);
  }

  /**
   * A factsheet with no sources cannot be composed.
   */
  public function testEmptySourcesThrows(): void {
    $this->expectException(ComposeException::class);
    $this->composer->compose('foo', $this->factsheet([]), "# Foo\n", '', NULL);
  }

  /**
   * A factsheet missing its provenance template cannot be composed.
   */
  public function testMissingTemplateThrows(): void {
    $this->expectException(ComposeException::class);
    $this->composer->compose('foo', ['module' => 'foo', 'identity' => []], "# Foo\n", '', NULL);
  }

}
