<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Search;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The droost-index binary's query modes read an index and never make one.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class DroostIndexBinTest extends TestCase {

  /**
   * A query against a repository never indexed refuses, and creates nothing.
   */
  public function testQueryWithNoIndexRefusesAndCreatesNothing(): void {
    $root = sys_get_temp_dir() . '/droost-index-bin-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, TRUE);
    try {
      foreach (['--find=Thing', '--callers=Thing', '--fan=thing'] as $mode) {
        exec(sprintf('%s %s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__, 2) . '/bin/droost-index'), escapeshellarg($root), escapeshellarg($mode)), $output, $exit);
        $this->assertSame(1, $exit, $mode . ' refuses');
        $this->assertStringContainsString('No index at', implode("\n", $output));
        $this->assertFileDoesNotExist($root . '/.droost-index.sqlite', $mode . ' created no index');
        $output = [];
      }
    }
    finally {
      @unlink($root . '/.droost-index.sqlite');
      rmdir($root);
    }
  }

  /**
   * An index an older extractor wrote is rebuilt whole, not incrementally.
   */
  public function testAnOlderIndexVersionRebuildsWhole(): void {
    $root = sys_get_temp_dir() . '/droost-index-bin-' . bin2hex(random_bytes(6));
    mkdir($root . '/src', 0777, TRUE);
    file_put_contents($root . '/src/Thing.php', "<?php\nnamespace App;\nfinal class Thing {}\n");
    $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/droost-index') . ' ' . escapeshellarg($root);
    try {
      exec($bin . ' 2>&1', $first);
      $this->assertStringStartsWith('Full index', $first[0] ?? '');
      exec($bin . ' 2>&1', $second);
      $this->assertStringStartsWith('Incremental index', $second[0] ?? '', 'an unchanged version runs incrementally');

      $pdo = new \PDO('sqlite:' . $root . '/.droost-index.sqlite');
      $pdo->exec('PRAGMA user_version = 1');
      $pdo = NULL;
      exec($bin . ' 2>&1', $third);
      $this->assertStringStartsWith('Full index', $third[0] ?? '', 'an older version rebuilds whole');
    }
    finally {
      @unlink($root . '/.droost-index.sqlite');
      @unlink($root . '/src/Thing.php');
      @rmdir($root . '/src');
      @rmdir($root);
    }
  }

}
