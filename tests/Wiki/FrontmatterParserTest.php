<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Wiki;

use Droost\Engine\Wiki\Okf\FrontmatterParser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests OKF frontmatter parsing, including the fail-closed droost verdicts.
 *
 * The parser never throws: agent-emitted YAML damage (tabs, unclosed
 * sequences) must become a per-page verdict, not an aborted status report.
 */
#[CoversClass(FrontmatterParser::class)]
final class FrontmatterParserTest extends TestCase {

  /**
   * The parser under test.
   */
  private FrontmatterParser $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->parser = new FrontmatterParser();
  }

  /**
   * A managed page parses OKF keys, the droost block, and the body.
   */
  public function testManagedPage(): void {
    $meta = $this->parser->parse(<<<MD
      ---
      type: "Drupal Module"
      title: "alpha"
      description: "A module page."
      tags: [drupal, custom]
      timestamp: "2026-07-10T12:00:00Z"
      resource: "docroot/modules/custom/alpha"
      droost:
        spec: 1
        modules: [alpha]
        sources:
          - path: docroot/modules/custom/alpha/alpha.module
            hash: "xxh3:9f86d081884c7d65"
      ---

      # alpha
      MD);
    $this->assertTrue($meta->isManaged());
    $this->assertFalse($meta->isInvalid());
    $this->assertSame('Drupal Module', $meta->type);
    $this->assertSame('alpha', $meta->title);
    $this->assertSame(['drupal', 'custom'], $meta->tags);
    $this->assertSame('2026-07-10T12:00:00Z', $meta->timestamp);
    $this->assertSame('docroot/modules/custom/alpha', $meta->resource);
    $this->assertNotNull($meta->provenance);
    $this->assertSame(['alpha'], $meta->provenance->modules);
    $this->assertSame('# alpha', $meta->body);
  }

  /**
   * A byte-order mark or a blank line before the fence hides nothing (F-503).
   *
   * The fence had to be the file's first bytes, so a managed page saved by
   * an editor that writes a BOM, or with a blank first line, parsed as
   * hand-written: it never went stale, whatever its sources did.
   */
  public function testManagedPageBehindBomOrBlankLineIsStillManaged(): void {
    $page = "---\ntitle: \"alpha\"\ndroost:\n  spec: 1\n  modules: [alpha]\n  sources:\n"
      . "    - path: a/alpha.module\n      hash: \"xxh3:9f86d081884c7d65\"\n---\n\n# alpha\n";
    $saved = [
      'a byte-order mark' => "\xEF\xBB\xBF" . $page,
      'a blank line' => "\n" . $page,
      'both' => "\xEF\xBB\xBF\r\n\n" . $page,
    ];
    foreach ($saved as $what => $raw) {
      $meta = $this->parser->parse($raw);
      $this->assertTrue($meta->isManaged(), $what);
      $this->assertSame('alpha', $meta->title, $what);
      $this->assertSame('# alpha', trim($meta->body), $what);
    }
  }

  /**
   * A droost block in a fence that never closes is invalid, not unmanaged.
   *
   * A page that claims provenance and cannot be read fails closed, as one
   * whose YAML will not parse already does.
   */
  public function testUnclosedFenceClaimingDroostIsInvalid(): void {
    $meta = $this->parser->parse("---\ntitle: alpha\ndroost:\n  spec: 1\n\n# alpha\n");
    $this->assertTrue($meta->isInvalid());

    $plain = $this->parser->parse("---\n\nA page that opens with a rule.\n");
    $this->assertFalse($plain->isInvalid());
    $this->assertFalse($plain->isManaged());
  }

  /**
   * A page without frontmatter is unmanaged.
   */
  public function testNoFrontmatterIsUnmanaged(): void {
    $meta = $this->parser->parse("# Just markdown\n");
    $this->assertFalse($meta->isManaged());
    $this->assertFalse($meta->isInvalid());
    $this->assertSame("# Just markdown\n", $meta->body);
  }

  /**
   * Frontmatter without a droost key is unmanaged, keys still parsed.
   */
  public function testFrontmatterWithoutDroostIsUnmanaged(): void {
    $meta = $this->parser->parse("---\ntype: \"Playbook\"\ntitle: \"Note\"\n---\n\nBody.\n");
    $this->assertFalse($meta->isManaged());
    $this->assertFalse($meta->isInvalid());
    $this->assertSame('Playbook', $meta->type);
    $this->assertSame('Body.', trim($meta->body));
  }

  /**
   * Malformed YAML claiming a droost block is invalid (fail-closed).
   */
  public function testMalformedYamlWithDroostIsInvalid(): void {
    $meta = $this->parser->parse("---\ntitle: \"delta\"\ndroost:\n  modules: [delta\n  sources:\n\t- broken\n---\n\nBody.\n");
    $this->assertFalse($meta->isManaged());
    $this->assertTrue($meta->isInvalid());
    $this->assertNotNull($meta->error);
    $this->assertStringContainsString('not valid YAML', $meta->error);
  }

  /**
   * Malformed YAML without a droost marker stays unmanaged.
   */
  public function testMalformedYamlWithoutDroostIsUnmanaged(): void {
    $meta = $this->parser->parse("---\ntitle: [unclosed\n---\n\nBody.\n");
    $this->assertFalse($meta->isManaged());
    $this->assertFalse($meta->isInvalid());
  }

  /**
   * A structurally valid droost block violating the contract is invalid.
   */
  public function testContractViolationIsInvalid(): void {
    $meta = $this->parser->parse("---\ndroost:\n  spec: 99\n  modules: [x]\n  sources:\n    - path: a.php\n      hash: \"xxh3:00ff\"\n---\n");
    $this->assertTrue($meta->isInvalid());
    $this->assertNotNull($meta->error);
    $this->assertStringContainsString('not a supported contract version', $meta->error);
  }

  /**
   * Scalar-only frontmatter (not a mapping) is unmanaged.
   */
  public function testScalarFrontmatterIsUnmanaged(): void {
    $meta = $this->parser->parse("---\njust a string\n---\n\nBody.\n");
    $this->assertFalse($meta->isManaged());
    $this->assertFalse($meta->isInvalid());
  }

  /**
   * A fence with no trailing body still parses.
   */
  public function testFrontmatterOnlyFile(): void {
    $meta = $this->parser->parse("---\ntype: \"Index\"\n---");
    $this->assertSame('Index', $meta->type);
    $this->assertSame('', $meta->body);
  }

  /**
   * Junk tags entries are dropped quietly (presentational, not contract).
   */
  public function testTagCoercion(): void {
    $meta = $this->parser->parse("---\ntags: [ok, 5, '', more]\n---\nBody.\n");
    $this->assertSame(['ok', 'more'], $meta->tags);
  }

}
