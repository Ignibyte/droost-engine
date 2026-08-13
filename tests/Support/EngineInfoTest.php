<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Support;

use Droost\Engine\Support\EngineInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the engine release identity.
 */
#[CoversClass(EngineInfo::class)]
final class EngineInfoTest extends TestCase {

  /**
   * The accessor reports the constant verbatim.
   */
  public function testVersionReportsTheConstant(): void {
    $this->assertSame(EngineInfo::VERSION, EngineInfo::version());
  }

  /**
   * The release string is semantic-version shaped.
   *
   * Consumers pin with a tilde range (~0.1.0), which composer can only honour
   * when every tag carries three numeric parts.
   */
  public function testVersionIsSemanticVersionShaped(): void {
    $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', EngineInfo::version());
  }

}
