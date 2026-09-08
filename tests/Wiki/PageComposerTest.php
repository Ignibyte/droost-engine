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
   * The author's chosen sources are recorded as given: their order, uncapped.
   *
   * Round 30 (T26): a component page's six default sources omitted the twig,
   * css and component.yml carrying its sharpest claims, and the author had no
   * way to say otherwise.
   */
  public function testChosenSourcesAreRecordedInOrderAndUncapped(): void {
    $factsheet = $this->factsheet([
      ['path' => 'foo/foo.info.yml', 'hash' => 'xxh3:1111111111111111'],
      ['path' => 'foo/src/A.php', 'hash' => 'xxh3:aaaaaaaaaaaaaaaa'],
      ['path' => 'foo/src/B.php', 'hash' => 'xxh3:bbbbbbbbbbbbbbbb'],
      ['path' => 'foo/components/bar/bar.twig', 'hash' => 'xxh3:cccccccccccccccc'],
      ['path' => 'foo/components/bar/bar.css', 'hash' => 'xxh3:dddddddddddddddd'],
      ['path' => 'foo/components/bar/bar.component.yml', 'hash' => 'xxh3:eeeeeeeeeeeeeeee'],
      ['path' => 'foo/config/optional/block.block.bar.yml', 'hash' => 'xxh3:ffffffffffffffff'],
      ['path' => 'foo/tests/src/Kernel/BarTest.php', 'hash' => 'xxh3:0000000000000000'],
    ]);
    $chosen = [
      'foo/components/bar/bar.twig',
      'foo/components/bar/bar.component.yml',
      'foo/components/bar/bar.css',
      'foo/config/optional/block.block.bar.yml',
      'foo/src/A.php',
      'foo/foo.info.yml',
      'foo/tests/src/Kernel/BarTest.php',
    ];

    $meta = $this->parser->parse($this->composer->compose('foo', $factsheet, "# Foo\n", '', NULL, $chosen));

    $this->assertNotNull($meta->provenance);
    $this->assertSame($chosen, array_column($meta->provenance->sources, 'path'), 'the author\'s list, in the author\'s order, seven long');
    $this->assertSame('xxh3:cccccccccccccccc', $meta->provenance->sources[0]['hash'], 'hashes come from the inventory');

    $selection = $this->composer->selection('foo', $factsheet, $chosen);
    $this->assertSame(['foo/src/B.php'], $selection['omitted'], 'the one inventory file the author left out is named');
  }

  /**
   * The default selection names what it omitted, so the author can decide.
   */
  public function testDefaultSelectionNamesTheOmitted(): void {
    $factsheet = $this->factsheet([
      ['path' => 'foo/src/A.php', 'hash' => 'xxh3:aaaaaaaaaaaaaaaa'],
      ['path' => 'foo/src/B.php', 'hash' => 'xxh3:bbbbbbbbbbbbbbbb'],
      ['path' => 'foo/src/C.php', 'hash' => 'xxh3:cccccccccccccccc'],
      ['path' => 'foo/src/D.php', 'hash' => 'xxh3:dddddddddddddddd'],
      ['path' => 'foo/foo.info.yml', 'hash' => 'xxh3:1111111111111111'],
      ['path' => 'foo/components/bar/bar.twig', 'hash' => 'xxh3:2222222222222222'],
      ['path' => 'foo/src/E.php', 'hash' => 'xxh3:eeeeeeeeeeeeeeee'],
      ['path' => 'foo/src/F.php', 'hash' => 'xxh3:ffffffffffffffff'],
    ]);

    $selection = $this->composer->selection('foo', $factsheet);

    $this->assertCount(PageComposer::MAX_SOURCES, $selection['sources']);
    $this->assertSame(['foo/components/bar/bar.twig', 'foo/src/F.php'], $selection['omitted'], 'the trim is visible: the component file and the sixth class fell off');
  }

  /**
   * A chosen path the factsheet never measured is refused by name.
   */
  public function testChosenSourceOutsideTheInventoryIsRefused(): void {
    $factsheet = $this->factsheet([
      ['path' => 'foo/foo.info.yml', 'hash' => 'xxh3:1111111111111111'],
    ]);

    $this->expectException(ComposeException::class);
    $this->expectExceptionMessage('chosen source(s) not in the factsheet inventory for "foo": foo/src/Ghost.php');
    $this->composer->compose('foo', $factsheet, "# Foo\n", '', NULL, ['foo/foo.info.yml', 'foo/src/Ghost.php']);
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
