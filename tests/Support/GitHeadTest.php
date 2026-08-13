<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Support;

use Droost\Engine\Support\GitHead;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the GitHead HEAD parser.
 */
#[CoversClass(GitHead::class)]
final class GitHeadTest extends TestCase {

  /**
   * The parser reduces HEAD content to exactly one of sha or ref.
   *
   * @param string $content
   *   The raw HEAD file content.
   * @param array{sha: string, ref: string} $expected
   *   The expected parse.
   */
  #[DataProvider('headCases')]
  public function testParseHead(string $content, array $expected): void {
    $this->assertSame($expected, GitHead::parseHead($content));
  }

  /**
   * HEAD-content cases.
   *
   * @return array<string, array{string, array{sha: string, ref: string}}>
   *   Label => [content, expected].
   */
  public static function headCases(): array {
    $sha = '0123456789abcdef0123456789abcdef01234567';
    return [
      'symbolic ref' => ["ref: refs/heads/main\n", ['sha' => '', 'ref' => 'refs/heads/main']],
      'symbolic ref padded' => ["ref:   refs/heads/feature/x  \n", ['sha' => '', 'ref' => 'refs/heads/feature/x']],
      'detached sha' => [$sha . "\n", ['sha' => $sha, 'ref' => '']],
      'detached sha no newline' => [$sha, ['sha' => $sha, 'ref' => '']],
      'garbage' => ["not a head\n", ['sha' => '', 'ref' => '']],
      'empty' => ['', ['sha' => '', 'ref' => '']],
      'short hex is not a sha' => ["abc123\n", ['sha' => '', 'ref' => '']],
    ];
  }

}
