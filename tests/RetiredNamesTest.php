<?php

declare(strict_types=1);

namespace Droost\Engine\Tests;

use PHPUnit\Framework\TestCase;

/**
 * No text the engine ships tells an agent to use droost's retired doors.
 *
 * The droost command left Drupal on 2026-10-19: no drush door, no MCP server,
 * no Drupal-era tool ids. The engine writes text agents read (skill
 * sentinels, the editors' pointers) and docblocks people read. This fails on a
 * retired name in either, and in the README outside its dated changelog,
 * which records each release as it shipped.
 *
 * The harness code that writes or removes an MCP server entry stays: an
 * install removes an old droost entry through it. Saying so is not
 * advertising MCP, so `MCP` alone is not retired here; the door's own names
 * (`droost-mcp`, `mcp:server`) are.
 */
final class RetiredNamesTest extends TestCase {

  /**
   * The retired names, each with why.
   */
  private const array RETIRED = [
    '/drush droost/' => 'droost has no drush door',
    '/droost:tool\b/' => 'droost has no drush door',
    '/mcp:server|droost-mcp/' => 'droost ships no MCP server',
    '/(?<![\w\/.-])droost-workflow (?:status|run|init|reset|bypass|baseline|effort)\b/' => 'the command is `droost`',
    '/vendor\/bin\/droost-workflow/' => 'the command is `droost`',
    '/\bdroost_(?:symbol|graph|search|wiki|brief|module_docs|deprecations|tool)\b/' => 'a Drupal-era tool id',
  ];

  /**
   * The engine's shipped text names nothing droost retired.
   */
  public function testNoShippedTextNamesTheRetiredDoors(): void {
    $root = dirname(__DIR__);
    $texts = [];
    $readme = (string) file_get_contents($root . '/README.md');
    // The record starts at the newest entry: **Unreleased**: or **1.0.0**:.
    $cut = preg_match('/\n\*\*(Unreleased|\d+\.\d+\.\d+)\*\*:/', $readme, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : FALSE;
    $texts['README.md (before the changelog)'] = $cut === FALSE ? $readme : substr($readme, 0, $cut);
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
        $kept = [];
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
          $read = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_DOC_COMMENT];
          if (is_array($token) && in_array($token[0], $read, TRUE)) {
            $kept[] = $token[1];
          }
        }
        $texts[substr($file->getPathname(), strlen($root) + 1)] = implode("\n", $kept);
      }
    }
    $this->assertArrayHasKey('src/Harness/ClaudeHarnessInstaller.php', $texts, 'the harness text is read');

    $found = [];
    foreach ($texts as $where => $text) {
      foreach (self::RETIRED as $pattern => $why) {
        if (preg_match_all($pattern, $text, $hits) > 0) {
          foreach (array_unique($hits[0]) as $hit) {
            $found[] = sprintf('%s: "%s" (%s)', $where, $hit, $why);
          }
        }
      }
    }

    $this->assertSame([], $found, 'Shipped text names droost\'s retired doors');
  }

}
