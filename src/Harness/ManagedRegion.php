<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Inserts, replaces, and removes a Droost-managed region in a text file.
 *
 * A managed region is the content between a begin and end marker. Everything
 * outside the markers is the user's and is never touched. Operations refuse to
 * act on a malformed region (mismatched/duplicate markers) rather than risk
 * destroying user content.
 */
final class ManagedRegion {

  /**
   * Whether the content contains a well-formed region (one pair, in order).
   *
   * @param string $content
   *   The file content.
   * @param string $begin
   *   The begin marker.
   * @param string $end
   *   The end marker.
   *
   * @return bool
   *   TRUE if exactly one begin and one end marker exist, begin before end.
   */
  public static function isWellFormed(string $content, string $begin, string $end): bool {
    if (substr_count($content, $begin) !== 1 || substr_count($content, $end) !== 1) {
      return FALSE;
    }
    return strpos($content, $begin) < strpos($content, $end);
  }

  /**
   * Whether the content contains any (begin or end) marker.
   *
   * @param string $content
   *   The file content.
   * @param string $begin
   *   The begin marker.
   * @param string $end
   *   The end marker.
   *
   * @return bool
   *   TRUE if either marker appears at least once.
   */
  public static function hasAnyMarker(string $content, string $begin, string $end): bool {
    return str_contains($content, $begin) || str_contains($content, $end);
  }

  /**
   * Inserts or replaces the managed region, returning the new content.
   *
   * @param string $content
   *   The existing file content (may be empty).
   * @param string $begin
   *   The begin marker.
   * @param string $end
   *   The end marker.
   * @param string $body
   *   The region body (without markers).
   *
   * @return string
   *   The content with the region upserted.
   *
   * @throws \RuntimeException
   *   If the content has a malformed region (mismatched/duplicate markers); the
   *   caller should warn and skip rather than risk clobbering user content.
   */
  public static function upsert(string $content, string $begin, string $end, string $body): string {
    $block = $begin . "\n" . $body . "\n" . $end;
    if (!self::hasAnyMarker($content, $begin, $end)) {
      return $content === '' ? $block . "\n" : rtrim($content) . "\n\n" . $block . "\n";
    }
    if (!self::isWellFormed($content, $begin, $end)) {
      throw new \RuntimeException('Malformed Droost managed region (mismatched or duplicate markers).');
    }
    // Non-greedy so a stray end marker elsewhere cannot extend the match.
    $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '/s';
    return preg_replace($pattern, self::escapeReplacement($block), $content) ?? $content;
  }

  /**
   * Removes the managed region, returning the new content.
   *
   * A malformed region is left untouched (safer than guessing its bounds).
   *
   * @param string $content
   *   The file content.
   * @param string $begin
   *   The begin marker.
   * @param string $end
   *   The end marker.
   *
   * @return string
   *   The content with the region removed and blank lines collapsed.
   */
  public static function strip(string $content, string $begin, string $end): string {
    if (!self::isWellFormed($content, $begin, $end)) {
      return $content;
    }
    $pattern = '/\n*' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\n*/s';
    $stripped = preg_replace($pattern, "\n", $content) ?? $content;
    return trim($stripped) === '' ? '' : rtrim($stripped) . "\n";
  }

  /**
   * Whether stripping the region leaves an empty file (so it can be deleted).
   *
   * @param string $content
   *   The file content.
   * @param string $begin
   *   The begin marker.
   * @param string $end
   *   The end marker.
   *
   * @return bool
   *   TRUE if nothing but the region (and whitespace) is present.
   */
  public static function isOnlyRegion(string $content, string $begin, string $end): bool {
    return self::isWellFormed($content, $begin, $end)
      && self::strip($content, $begin, $end) === '';
  }

  /**
   * Escapes a replacement string for use in preg_replace.
   *
   * @param string $replacement
   *   The literal replacement text.
   *
   * @return string
   *   The escaped replacement (backslashes and dollar signs neutralised).
   */
  private static function escapeReplacement(string $replacement): string {
    return str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement);
  }

}
