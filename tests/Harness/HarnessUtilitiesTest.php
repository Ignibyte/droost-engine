<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Harness;

use Droost\Engine\Harness\JsonMerge;
use Droost\Engine\Harness\ManagedRegion;
use Droost\Engine\Harness\Toml;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the harness install utilities.
 */
final class HarnessUtilitiesTest extends TestCase {

  private const string BEGIN = '<!-- B -->';
  private const string END = '<!-- E -->';

  /**
   * ManagedRegion upsert is idempotent and preserves surrounding content.
   */
  public function testManagedRegionUpsertIdempotent(): void {
    $content = "user top\n";
    $once = ManagedRegion::upsert($content, self::BEGIN, self::END, 'body v1');
    $this->assertStringContainsString('user top', $once);
    $this->assertStringContainsString('body v1', $once);

    $twice = ManagedRegion::upsert($once, self::BEGIN, self::END, 'body v2');
    $this->assertSame(1, substr_count($twice, self::BEGIN), 'Exactly one begin marker.');
    $this->assertStringContainsString('body v2', $twice);
    $this->assertStringNotContainsString('body v1', $twice, 'Old body replaced.');
    $this->assertStringContainsString('user top', $twice, 'User content preserved.');
  }

  /**
   * ManagedRegion strip restores the original and no-ops without a region.
   */
  public function testManagedRegionStrip(): void {
    $original = "user top\n";
    $withRegion = ManagedRegion::upsert($original, self::BEGIN, self::END, 'body');
    $this->assertSame($original, ManagedRegion::strip($withRegion, self::BEGIN, self::END));
    // No region present -> unchanged.
    $this->assertSame('plain', ManagedRegion::strip('plain', self::BEGIN, self::END));
    // Only-region content strips to empty.
    $only = ManagedRegion::upsert('', self::BEGIN, self::END, 'body');
    $this->assertSame('', ManagedRegion::strip($only, self::BEGIN, self::END));
    $this->assertTrue(ManagedRegion::isOnlyRegion($only, self::BEGIN, self::END));
  }

  /**
   * A replacement body with $ and backslashes is inserted literally.
   */
  public function testManagedRegionEscapesReplacement(): void {
    $body = 'price $5 and a \\ backslash and $1 group';
    $out = ManagedRegion::upsert('top', self::BEGIN, self::END, $body);
    $this->assertStringContainsString($body, $out, 'Special chars are literal, not regex backrefs.');
  }

  /**
   * A malformed region (lone or duplicate marker) is refused, never clobbered.
   */
  public function testManagedRegionRefusesMalformed(): void {
    // Begin without end: upsert must throw (caller warns + skips) rather than
    // append a second region that a later run would use to eat user content.
    $loneBegin = self::BEGIN . "\nIMPORTANT USER DATA\n";
    try {
      ManagedRegion::upsert($loneBegin, self::BEGIN, self::END, 'new');
      $this->fail('Expected a RuntimeException for a lone begin marker.');
    }
    catch (\RuntimeException) {
      // Expected.
    }
    // Strip leaves a malformed region untouched (no guessing its bounds).
    $this->assertSame($loneBegin, ManagedRegion::strip($loneBegin, self::BEGIN, self::END));
    $this->assertFalse(ManagedRegion::isWellFormed($loneBegin, self::BEGIN, self::END));

    // Duplicate begin markers are also malformed.
    $dup = self::BEGIN . "\na\n" . self::BEGIN . "\nb\n" . self::END;
    $this->assertFalse(ManagedRegion::isWellFormed($dup, self::BEGIN, self::END));
  }

  /**
   * JsonMerge preserves siblings on set and drops emptied parents on unset.
   */
  public function testJsonMerge(): void {
    $data = ['mcpServers' => ['other' => ['command' => 'x']]];
    $data = JsonMerge::setPath($data, 'mcpServers', 'droost', ['command' => 'ddev']);
    $servers = $data['mcpServers'];
    $this->assertIsArray($servers);
    $this->assertArrayHasKey('other', $servers);
    $this->assertArrayHasKey('droost', $servers);

    $data = JsonMerge::unsetPath($data, 'mcpServers', 'droost');
    $kept = $data['mcpServers'];
    $this->assertIsArray($kept);
    $this->assertArrayHasKey('other', $kept, 'Sibling kept.');

    $emptied = JsonMerge::unsetPath(['mcpServers' => ['droost' => []]], 'mcpServers', 'droost');
    $this->assertArrayNotHasKey('mcpServers', $emptied, 'Emptied parent dropped.');
  }

  /**
   * Toml emits a valid escaped table and string array.
   */
  public function testTomlEmitter(): void {
    $this->assertSame('"a\\"b"', Toml::string('a"b'), 'Quotes are escaped.');
    $this->assertSame('["drush", "mcp:server"]', Toml::stringArray(['drush', 'mcp:server']));
    $table = Toml::mcpServerTable('droost', 'ddev', ['drush', 'mcp:server']);
    $this->assertStringContainsString('[mcp_servers.droost]', $table);
    $this->assertStringContainsString('command = "ddev"', $table);
    $this->assertStringContainsString('args = ["drush", "mcp:server"]', $table);
  }

}
