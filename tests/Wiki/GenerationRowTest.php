<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Wiki;

use Droost\Engine\Wiki\GenerationRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the per-page outcome of a wiki generation.
 */
#[CoversClass(GenerationRow::class)]
final class GenerationRowTest extends TestCase {

  /**
   * A page written, or already right, is a success; the rest are not.
   */
  public function testOutcomes(): void {
    $wrote = GenerationRow::wrote('m', 'droost/wiki/m.md', ['a.php']);
    $this->assertTrue($wrote->ok());
    $this->assertSame(['wrote', 'fresh', ['a.php']], [$wrote->action, $wrote->verdict, $wrote->omitted]);

    $unchanged = GenerationRow::unchanged('m', 'droost/wiki/m.md');
    $this->assertTrue($unchanged->ok(), 'a page already saying exactly this is fresh');
    $this->assertSame('unchanged', $unchanged->action);
    $this->assertSame('fresh', $unchanged->verdict);
    $this->assertSame('droost/wiki/m.md', $unchanged->path);

    $this->assertFalse(GenerationRow::refused('m', 'not-installed', 'x')->ok());
    $this->assertFalse(GenerationRow::failed('m', 'not-fresh', 'x')->ok());
  }

}
