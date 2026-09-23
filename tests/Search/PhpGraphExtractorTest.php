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
   * Only a declaration guarded by its own absence is a fallback.
   *
   * 0.7.2 skipped every conditional declaration, and a full rebuild of a real
   * site showed what that cost: webform declares WebformManagedFileBase in
   * both branches of a feature check, and easy_email_theme declares a
   * preprocess hook only while symfony_mailer is on. Both are the real, and
   * only, declaration of their name. What makes a declaration a stand-in is
   * the guard `if (!class_exists('X')) { class X … }`: it exists only when the
   * real X does not.
   */
  public function testOnlySelfGuardedDeclarationIsSkipped(): void {
    $code = <<<'PHP'
<?php

namespace Drupal\fx;

use Drupal\Core\Render\Element\ManagedFile;

if (class_exists(ManagedFile::class)) {
  abstract class FileBase extends ManagedFile {}
}
else {
  abstract class FileBase extends Base {}
}

if (\Drupal::moduleHandler()->moduleExists('mailer')) {
  function fx_preprocess_email(array &$variables): void {}
}

if (!function_exists('Drupal\fx\t')) {
  function t(string $s): string {
    return Translator::translate($s);
  }
}

if (!class_exists(Legacy::class)) {
  class Legacy {}
}

if (!interface_exists('\Drupal\fx\Named')) {
  interface Named {}
}

function define_late(): void {
  class Late {}
}
PHP;
    $result = (new PhpGraphExtractor())->extract($code, 'modules/custom/fx/fx.module', 'fx');

    $this->assertSame(
      [
        'Drupal\fx\FileBase',
        'Drupal\fx\FileBase',
        'Drupal\fx\fx_preprocess_email',
        'Drupal\fx\define_late',
        'Drupal\fx\Late',
      ],
      array_column($result['symbols'], 'fqcn'),
      'a feature check, a module check and a function body are real declarations; the three self-guarded ones are not',
    );
    $edges = array_map(static fn(array $e): string => $e['src'] . '|' . $e['dst'] . '|' . $e['kind'], $result['edges']);
    $this->assertNotContains('Drupal\fx\t|Drupal\fx\Translator|calls', $edges, 'a stand-in sources no edge');
  }

}
