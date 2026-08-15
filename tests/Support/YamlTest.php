<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Support;

use Droost\Engine\Support\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the YAML formatting the wiki's byte-identical promise rests on.
 *
 * This class exists because Symfony's defaults are not Drupal's, and the
 * differences are exactly the ones that turn a no-op regeneration into a
 * diff: 4-space indent instead of 2, and inlining nested structures that
 * Drupal never inlines. Both were verified against core's
 * \Drupal\Component\Serialization\Yaml on a booted site before this was
 * written; these cases are what stops the settings drifting back.
 *
 * A test asserting only "it round-trips" would pass under either formatting
 * and catch nothing, so the expectations here are literal output — except
 * where the layout belongs to symfony/yaml rather than to us.
 *
 * That exception is real and worth knowing about. symfony/yaml 8 compacts
 * block sequences of mappings ("- k: 1") where 7 expanded them ("-\n  k: 1"),
 * with identical dump flags. Droost and Drupal resolve ONE symfony/yaml per
 * project, so a site's pages stay consistent with each other and with what
 * Drupal itself writes — but a project that crosses that Symfony major will
 * reformat every page with a list in its frontmatter exactly once, and the
 * wiki's `sources:` list is one. Expected churn, not a regression.
 */
final class YamlTest extends TestCase {

  /**
   * Nested structures indent by two and are never inlined.
   */
  public function testNestedMappingUsesTwoSpaceIndentAndNoInlining(): void {
    $yaml = Yaml::encode(['nested' => ['outer' => ['inner' => 1]]]);

    $this->assertSame("nested:\n  outer:\n    inner: 1\n", $yaml);
  }

  /**
   * Keys YAML 1.1 reads as booleans are quoted, so they survive a round trip.
   *
   * `y`, `n`, `on` and `off` are booleans in YAML 1.1. An unquoted `y:` key
   * would come back as the string "1", silently renaming a field. Worth a
   * test because it is invisible until some page happens to use one of those
   * names, and then the corruption looks like a bug anywhere but here.
   */
  public function testBooleanLikeKeysAreQuoted(): void {
    $yaml = Yaml::encode(['y' => 1, 'on' => 2, 'no' => 3]);

    $this->assertStringContainsString("'y': 1", $yaml);
    $this->assertSame(['y' => 1, 'on' => 2, 'no' => 3], Yaml::decode($yaml));
  }

  /**
   * A deep structure still never collapses onto one line.
   *
   * Symfony's default inline level is 2; Drupal passes PHP_INT_MAX. Without
   * that, everything below the second level would come back as `{ }` flow
   * mapping and every regenerated page would differ from the last.
   */
  public function testDeepStructureIsNeverCollapsed(): void {
    $yaml = Yaml::encode(['l1' => ['l2' => ['l3' => ['l4' => 'bottom']]]]);

    $this->assertStringNotContainsString('{', $yaml);
    $this->assertSame("l1:\n  l2:\n    l3:\n      l4: bottom\n", $yaml);
  }

  /**
   * Multi-line strings render as literal blocks, not escaped one-liners.
   */
  public function testMultiLineStringUsesLiteralBlock(): void {
    $yaml = Yaml::encode(['body' => "line one\nline two\n"]);

    $this->assertStringContainsString('|', $yaml);
    $this->assertStringNotContainsString('\n', $yaml);
  }

  /**
   * A list of mappings stays a block sequence, never flow style.
   *
   * Deliberately not asserting the exact layout. symfony/yaml 7 writes the
   * dash on its own line ("-\n    k: 1") and symfony/yaml 8 compacts it
   * ("- k: 1"), and neither is something this class chooses — the flags are
   * identical on both. Pinning one spelling made this test fail on CI while
   * passing locally, which said nothing about Droost and everything about
   * which Symfony happened to resolve.
   *
   * What IS ours, and what would break the wiki, is asserted: block style
   * rather than flow, and a two-space indent. See the class docblock for what
   * the version difference means for byte-identical regeneration.
   */
  public function testListOfMappingsStaysBlockFormatted(): void {
    $yaml = Yaml::encode(['rows' => [['k' => 1], ['k' => 2]]]);

    $this->assertStringStartsWith("rows:\n  -", $yaml);
    $this->assertStringNotContainsString('{', $yaml);
    $this->assertStringNotContainsString('[', $yaml);
    $this->assertSame(['rows' => [['k' => 1], ['k' => 2]]], Yaml::decode($yaml));
  }

  /**
   * Values that would break a naive writer survive a round trip.
   *
   * @param mixed $value
   *   The value to round-trip.
   */
  #[DataProvider('awkwardValues')]
  public function testRoundTrip(mixed $value): void {
    $this->assertSame(['v' => $value], Yaml::decode(Yaml::encode(['v' => $value])));
  }

  /**
   * Values whose encoding is easy to get wrong.
   *
   * @return array<string, array{mixed}>
   *   The values.
   */
  public static function awkwardValues(): array {
    return [
      'a colon' => ['has: colon'],
      'a hash' => ['a # b'],
      'empty string' => [''],
      'null' => [NULL],
      'true' => [TRUE],
      'false' => [FALSE],
      'zero' => [0],
      'a numeric string' => ['0123'],
      'a leading dash' => ['- not a list'],
      'trailing whitespace' => ['padded '],
    ];
  }

  /**
   * Malformed YAML throws, so a caller can decide what it means.
   */
  public function testMalformedYamlThrows(): void {
    $this->expectException(ParseException::class);
    Yaml::decode("a:\n  - b\n c: broken\n");
  }

  /**
   * Malformed input yields NULL from decodeOrNull(), and nothing else does.
   */
  public function testDecodeOrNullReturnsNullOnMalformedInput(): void {
    $this->assertNull(Yaml::decodeOrNull("a:\n  - b\n c: broken\n"));
    $this->assertSame(['a' => 1], Yaml::decodeOrNull("a: 1\n"));
  }

}
