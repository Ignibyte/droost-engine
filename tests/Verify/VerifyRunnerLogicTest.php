<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Verify;

use Droost\Engine\Support\ProjectRoot;
use Droost\Engine\Verify\VerifyRunner;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure (process-independent) verify argv + report parsing.
 */
final class VerifyRunnerLogicTest extends TestCase {

  /**
   * The phpcs argv is a quiet JSON report with the standard + target.
   */
  public function testBuildPhpcsArgv(): void {
    $argv = VerifyRunner::buildPhpcsArgv('/bin/phpcs', '/mod', 'Drupal,DrupalPractice');
    $this->assertSame(
      ['/bin/phpcs', '-q', '--report=json', '--standard=Drupal,DrupalPractice', '/mod'],
      $argv,
    );
  }

  /**
   * The phpstan argv uses a module-local config when given, else --level=max.
   */
  public function testBuildPhpstanArgv(): void {
    $withConfig = VerifyRunner::buildPhpstanArgv('/bin/phpstan', '/mod', '/mod/phpstan.neon');
    $this->assertSame(
      ['/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '-c', '/mod/phpstan.neon', '/mod'],
      $withConfig,
    );

    $noConfig = VerifyRunner::buildPhpstanArgv('/bin/phpstan', '/mod', NULL);
    $this->assertContains('--level=max', $noConfig);
    $this->assertNotContains('-c', $noConfig);
    $this->assertSame('/mod', end($noConfig));
  }

  /**
   * The phpunit argv passes a discoverable config via -c, else just the target.
   */
  public function testBuildPhpunitArgv(): void {
    $this->assertSame(
      ['/bin/phpunit', '-c', '/core/phpunit.xml.dist', '/mod'],
      VerifyRunner::buildPhpunitArgv('/bin/phpunit', '/mod', '/core/phpunit.xml.dist'),
    );
    $this->assertSame(
      ['/bin/phpunit', '/mod'],
      VerifyRunner::buildPhpunitArgv('/bin/phpunit', '/mod', NULL),
    );
  }

  /**
   * A clean phpcs report passes with no findings; issues fail with findings.
   */
  public function testParsePhpcsJson(): void {
    $clean = VerifyRunner::parsePhpcsJson('{"totals":{"errors":0,"warnings":0},"files":{}}', 0);
    $this->assertTrue($clean['passed']);
    $this->assertSame([], $clean['findings']);

    $report = '{"totals":{"errors":1,"warnings":1},"files":{"/a/File.php":{"messages":['
      . '{"message":"Missing param","source":"Drupal.Commenting.X","severity":5,"type":"ERROR","line":10,"column":3},'
      . '{"message":"Long line","source":"Drupal.Files.Y","severity":5,"type":"WARNING","line":22,"column":1}'
      . ']}}}';
    $parsed = VerifyRunner::parsePhpcsJson($report, 1);
    $this->assertFalse($parsed['passed'], '1 error + 1 warning is not green');
    $this->assertCount(2, $parsed['findings']);
    $this->assertSame('/a/File.php', $parsed['findings'][0]['file']);
    $this->assertSame(10, $parsed['findings'][0]['line']);
    $this->assertSame('error', $parsed['findings'][0]['severity']);
    $this->assertSame('Drupal.Commenting.X', $parsed['findings'][0]['source']);
    $this->assertStringContainsString('1 error', $parsed['summary']);

    // Unparseable output falls back to the exit code, no findings.
    $garbage = VerifyRunner::parsePhpcsJson('not json', 0);
    $this->assertTrue($garbage['passed']);
    $this->assertSame([], $garbage['findings']);
    $this->assertFalse(VerifyRunner::parsePhpcsJson('boom', 2)['passed']);
  }

  /**
   * A clean phpstan report passes; file + general errors become findings.
   */
  public function testParsePhpstanJson(): void {
    $clean = VerifyRunner::parsePhpstanJson('{"totals":{"errors":0,"file_errors":0},"files":{}}', 0);
    $this->assertTrue($clean['passed']);

    $report = '{"totals":{"errors":1,"file_errors":1},"files":{"/a/File.php":{"messages":['
      . '{"message":"Access to undefined property","line":12,"ignorable":true}]}},'
      . '"errors":["Config parameter is invalid"]}';
    $parsed = VerifyRunner::parsePhpstanJson($report, 1);
    $this->assertFalse($parsed['passed']);
    $this->assertCount(2, $parsed['findings'], 'one file error + one general error');
    $this->assertSame('/a/File.php', $parsed['findings'][0]['file']);
    $this->assertSame('phpstan', $parsed['findings'][0]['source']);
    $this->assertSame('(general)', $parsed['findings'][1]['file']);
    $this->assertStringContainsString('2 error', $parsed['summary']);

    $garbage = VerifyRunner::parsePhpstanJson('nope', 1);
    $this->assertFalse($garbage['passed']);
    $this->assertSame([], $garbage['findings']);
  }

  /**
   * The deprecations argv is phpstan at level 0 with a JSON report, no -c.
   */
  public function testBuildDeprecationsArgv(): void {
    $this->assertSame(
      ['/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--level=0', '/mod'],
      VerifyRunner::buildDeprecationsArgv('/bin/phpstan', '/mod'),
    );
  }

  /**
   * The deprecations parse is the phpstan walk with the source retagged.
   */
  public function testParseDeprecationsJson(): void {
    $report = '{"totals":{"errors":1,"file_errors":1},"files":{"/a/File.php":{"messages":['
      . '{"message":"Call to deprecated function foo(). Use bar() instead.","line":7,"ignorable":true}]}},'
      . '"errors":["Config parameter is invalid"]}';
    $parsed = VerifyRunner::parseDeprecationsJson($report, 1);
    $this->assertFalse($parsed['passed']);
    $this->assertCount(2, $parsed['findings'], 'one file finding + one general finding');
    foreach ($parsed['findings'] as $finding) {
      $this->assertSame('deprecation', $finding['source'], 'every deprecations finding is leg-tagged');
    }
    $this->assertSame('/a/File.php', $parsed['findings'][0]['file']);
    $this->assertSame(7, $parsed['findings'][0]['line']);

    $clean = VerifyRunner::parseDeprecationsJson('{"totals":{"errors":0,"file_errors":0},"files":{}}', 0);
    $this->assertTrue($clean['passed']);
    $this->assertSame([], $clean['findings']);
  }

  /**
   * The deprecations leg resolves the phpstan binary and gates on the ext.
   */
  public function testDeprecationsBinaryAndExtensionGate(): void {
    $root = sys_get_temp_dir() . '/droost-verify-' . uniqid();
    mkdir($root . '/vendor/bin', 0777, TRUE);
    touch($root . '/vendor/bin/phpstan');
    try {
      $runner = new VerifyRunner($root . '/web', new ProjectRoot($root . '/web'));

      $inventory = $runner->inventory();
      $this->assertSame(
        $root . '/vendor/bin/phpstan',
        $inventory['deprecations']['binary'],
        'the leg resolves the phpstan binary, not vendor/bin/deprecations',
      );
      $config = $inventory['deprecations']['config'] ?? NULL;
      $this->assertIsString($config);
      $this->assertStringContainsString('MISSING', $config);

      // Extension absent → a structured leg row BEFORE any spawn.
      $legs = $runner->run('/anything', ['deprecations'], NULL);
      $this->assertCount(1, $legs);
      $row = $legs[0]->toArray();
      $this->assertFalse($row['passed']);
      $this->assertSame(-1, $row['exit_code']);
      $summary = $row['summary'] ?? NULL;
      $this->assertIsString($summary);
      $this->assertStringContainsString('mglaman/phpstan-drupal', $summary);
    }
    finally {
      unlink($root . '/vendor/bin/phpstan');
      rmdir($root . '/vendor/bin');
      rmdir($root . '/vendor');
      rmdir($root);
    }
  }

  /**
   * The phpunit parse reports pass/fail by exit code and lifts a summary line.
   */
  public function testParsePhpunitOutput(): void {
    $ok = VerifyRunner::parsePhpunitOutput("PHPUnit 12.5\n\nOK (5 tests, 12 assertions)\n", '', 0);
    $this->assertTrue($ok['passed']);
    $this->assertStringContainsString('OK (5 tests', $ok['summary']);

    $fail = VerifyRunner::parsePhpunitOutput("F\n\nFAILURES!\nTests: 5, Assertions: 12, Failures: 1.\n", '', 1);
    $this->assertFalse($fail['passed']);
    $this->assertStringContainsString('FAILURES!', $fail['summary']);

    // A bootstrap failure lands on stderr; the leg still reports.
    $boot = VerifyRunner::parsePhpunitOutput('', "Cannot open file \"…/bootstrap.php\".\n", 1);
    $this->assertFalse($boot['passed']);
    $this->assertStringContainsString('Cannot open', $boot['summary']);
  }

}
