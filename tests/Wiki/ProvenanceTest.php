<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Wiki;

use Droost\Engine\Wiki\Okf\Provenance;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests provenance validation — the wiki staleness contract's gatekeeper.
 *
 * Every rejection carries a precise reason because it surfaces verbatim as an
 * "invalid" page verdict in status reports; an agent (or a human) has to be
 * able to fix the page from the message alone.
 */
#[CoversClass(Provenance::class)]
final class ProvenanceTest extends TestCase {

  /**
   * A minimal valid droost block.
   *
   * @return array<string, mixed>
   *   The block.
   */
  private static function valid(): array {
    return [
      'spec' => 1,
      'modules' => ['alpha'],
      'sources' => [
        ['path' => 'docroot/modules/custom/alpha/alpha.module', 'hash' => 'xxh3:9f86d081884c7d65'],
      ],
    ];
  }

  /**
   * The happy path populates every field.
   */
  public function testHappyPath(): void {
    $data = self::valid() + [
      'generated_commit' => 'abc1234',
      'generator' => 'golden-fixture',
      'queries' => ['droost_wiki_factsheet'],
    ];
    $provenance = Provenance::fromArray($data);
    $this->assertSame(1, $provenance->spec);
    $this->assertSame(['alpha'], $provenance->modules);
    $this->assertSame('docroot/modules/custom/alpha/alpha.module', $provenance->sources[0]['path']);
    $this->assertSame('xxh3:9f86d081884c7d65', $provenance->sources[0]['hash']);
    $this->assertSame('abc1234', $provenance->generatedCommit);
    $this->assertSame('golden-fixture', $provenance->generator);
    $this->assertSame(['droost_wiki_factsheet'], $provenance->queries);
  }

  /**
   * Optional keys default cleanly.
   */
  public function testOptionalKeysDefault(): void {
    $provenance = Provenance::fromArray(self::valid());
    $this->assertNull($provenance->generatedCommit);
    $this->assertNull($provenance->generator);
    $this->assertSame([], $provenance->queries);
  }

  /**
   * Each malformed shape is rejected with its precise reason.
   *
   * @param mixed $data
   *   The malformed droost block.
   * @param string $reason
   *   The expected message fragment.
   */
  #[DataProvider('malformedProvider')]
  public function testMalformedRejected(mixed $data, string $reason): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($reason);
    Provenance::fromArray($data);
  }

  /**
   * Malformed droost blocks and their expected reasons.
   *
   * @return array<string, array{mixed, string}>
   *   Case name => [data, message fragment].
   */
  public static function malformedProvider(): array {
    $valid = self::valid();
    $hash = 'xxh3:9f86d081884c7d65';
    $sources = static fn(array $source): array => ['sources' => [$source]];
    return [
      'not a mapping' => ['nope', 'not a mapping'],
      'missing spec' => [array_diff_key($valid, ['spec' => 1]), 'droost.spec is missing'],
      'string spec' => [['spec' => '1'] + $valid, 'droost.spec is missing or not an integer'],
      'unknown spec' => [['spec' => 99] + $valid, 'not a supported contract version'],
      'missing modules' => [array_diff_key($valid, ['modules' => 1]), 'droost.modules is missing'],
      'empty modules' => [
        ['modules' => []] + $valid,
        'droost.modules is missing or not a non-empty list',
      ],
      'non-string module' => [['modules' => [5]] + $valid, 'droost.modules contains a non-string'],
      'missing sources' => [array_diff_key($valid, ['sources' => 1]), 'droost.sources is missing'],
      'sources not a list' => [
        ['sources' => ['path' => 'a', 'hash' => 'xxh3:00']] + $valid,
        'droost.sources is missing or not a non-empty list',
      ],
      'source not a mapping' => [['sources' => ['a']] + $valid, 'droost.sources[0] is not a mapping'],
      'source missing path' => [
        $sources(['hash' => $hash]) + $valid,
        'droost.sources[0].path is missing',
      ],
      'source non-string path' => [
        $sources(['path' => 4, 'hash' => $hash]) + $valid,
        'droost.sources[0].path is missing',
      ],
      'absolute path' => [
        $sources(['path' => '/etc/passwd', 'hash' => $hash]) + $valid,
        'project-root-relative',
      ],
      'dotdot path' => [
        $sources(['path' => 'a/../b.php', 'hash' => $hash]) + $valid,
        'project-root-relative',
      ],
      'backslash path' => [
        $sources(['path' => 'a\\b.php', 'hash' => $hash]) + $valid,
        'project-root-relative',
      ],
      'missing hash' => [
        $sources(['path' => 'a.php']) + $valid,
        'droost.sources[0].hash is not an algo-prefixed',
      ],
      'unprefixed hash' => [
        $sources(['path' => 'a.php', 'hash' => '9f86d081884c7d65']) + $valid,
        'algo-prefixed',
      ],
      'uppercase hash' => [
        $sources(['path' => 'a.php', 'hash' => 'xxh3:9F86D081884C7D65']) + $valid,
        'algo-prefixed',
      ],
      'non-string commit' => [
        ['generated_commit' => 7] + $valid,
        'droost.generated_commit is not a string',
      ],
      'non-list queries' => [
        ['queries' => 'droost_search'] + $valid,
        'droost.queries is missing or not a non-empty list',
      ],
    ];
  }

}
