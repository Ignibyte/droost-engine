<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Support;

use Droost\Engine\Support\CoreVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests CoreVersion.
 */
#[CoversClass(CoreVersion::class)]
final class CoreVersionTest extends TestCase {

  /**
   * The major derives from the version string, malformed disabling it.
   *
   * @param string $version
   *   The version string.
   * @param string $expected
   *   The expected major.
   */
  #[DataProvider('majorCases')]
  public function testMajor(string $version, string $expected): void {
    $this->assertSame($expected, CoreVersion::major($version));
  }

  /**
   * Version-derivation cases, ported unchanged from the guideline provider.
   *
   * @return array<int, array{string, string}>
   *   [version, expected major].
   */
  public static function majorCases(): array {
    return [
      ['11.4.2', '11'],
      ['10.3.0', '10'],
      ['12.0.0-dev', '12'],
      ['9.5.11', '9'],
      ['abc', ''],
      ['', ''],
      ['x.1', ''],
    ];
  }

}
