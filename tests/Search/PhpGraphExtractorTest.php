<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Search;

use Droost\Engine\Search\Graph\PhpGraphExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Tests which PHP declarations the code graph records as symbols.
 */
final class PhpGraphExtractorTest extends TestCase {

  /**
   * A class declared only when the real one is missing is not the class.
   *
   * The shape is project_browser's fixture script, which declares a stand-in
   * `Drupal` so it can run outside Drupal. The index carries no core, so that
   * stand-in was the only `Drupal` symbol there was, and every `\Drupal::`
   * call in the codebase was attributed to project_browser (F-63).
   */
  public function testFallbackClassIsNotTheClass(): void {
    $code = <<<'PHP'
<?php

if (!class_exists('Drupal')) {
  class Drupal {
    const VERSION = '11.x';

    public static function service(string $id): object {
      return Registry::get($id);
    }

  }
}
PHP;
    $result = (new PhpGraphExtractor())->extract($code, 'modules/contrib/pb/scripts/fixture.php', 'pb');

    $this->assertSame([], array_column($result['symbols'], 'fqcn'), 'Neither the stand-in nor its method is a symbol.');
    $this->assertSame([], $result['edges'], 'A declaration that is not recorded is the source of no edge.');
  }

  /**
   * Calls to the real class are still edges; only the owner changes.
   */
  public function testCallsToTheRealClassAreStillRecorded(): void {
    $code = <<<'PHP'
<?php

namespace Drupal\fx;

final class Seeder {

  public function run(): void {
    \Drupal::service('entity_type.manager');
  }

}
PHP;
    $result = (new PhpGraphExtractor())->extract($code, 'modules/custom/fx/src/Seeder.php', 'fx');

    $this->assertSame(['Drupal\fx\Seeder', 'Drupal\fx\Seeder::run'], array_column($result['symbols'], 'fqcn'));
    $edges = array_map(static fn(array $e): string => $e['src'] . '|' . $e['dst'] . '|' . $e['kind'], $result['edges']);
    $this->assertContains('Drupal\fx\Seeder::run|Drupal|calls', $edges);
    $this->assertContains('Drupal\fx\Seeder::run|service:entity_type.manager|uses_service', $edges);
  }

  /**
   * Namespace and declare blocks do not make a declaration conditional.
   */
  public function testDeclarationsInNamespaceAndDeclareBlocksAreRecorded(): void {
    $braced = <<<'PHP'
<?php

namespace Drupal\fx {
  class Braced {}
  function helper(): void {}
}
PHP;
    $declared = <<<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\fx;

interface Plain {}
PHP;
    $extractor = new PhpGraphExtractor();

    $this->assertSame(
      ['Drupal\fx\Braced', 'Drupal\fx\helper'],
      array_column($extractor->extract($braced, 'a.php', 'fx')['symbols'], 'fqcn'),
    );
    $this->assertSame(
      ['Drupal\fx\Plain'],
      array_column($extractor->extract($declared, 'b.php', 'fx')['symbols'], 'fqcn'),
    );
  }

  /**
   * A class in either branch of a version check, or in a function, is neither.
   *
   * Each one exists only once some code has run, so none of them is the
   * definition a reference resolves to.
   */
  public function testEveryConditionalDeclarationIsSkipped(): void {
    $code = <<<'PHP'
<?php

namespace Drupal\fx;

if (PHP_VERSION_ID >= 80400) {
  class Modern extends Base {}
}
elseif (PHP_VERSION_ID >= 80300) {
  class Middle extends Base {}
}
else {
  class Legacy extends Base {}
}

if (!function_exists('Drupal\fx\t')) {
  function t(string $s): string {
    return Translator::translate($s);
  }
}

function define_late(): void {
  class Late {}
}
PHP;
    $result = (new PhpGraphExtractor())->extract($code, 'modules/custom/fx/fx.module', 'fx');

    $this->assertSame(['Drupal\fx\define_late'], array_column($result['symbols'], 'fqcn'));
    $this->assertSame([], $result['edges']);
  }

}
