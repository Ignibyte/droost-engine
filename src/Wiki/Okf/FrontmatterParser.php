<?php

declare(strict_types=1);

namespace Droost\Engine\Wiki\Okf;

use Droost\Engine\Support\Yaml;
use Symfony\Component\Yaml\Exception\ExceptionInterface;

/**
 * Parses OKF page frontmatter, including the droost provenance extension.
 *
 * Never throws: every parse failure becomes a PageMeta verdict. A page whose
 * frontmatter is unparseable is only "invalid" (failing) when it visibly
 * claims a droost block — otherwise it is "unmanaged" (hand-written content
 * never fails staleness). This is a full YAML parse, unlike SkillProvider's
 * flat name/description scan, because the droost block is a nested mapping.
 */
final readonly class FrontmatterParser {

  /**
   * Parses raw page content into metadata + body.
   *
   * @param string $raw
   *   The page file content.
   *
   * @return \Droost\Engine\Wiki\Okf\PageMeta
   *   The parse verdict.
   */
  public function parse(string $raw): PageMeta {
    if (preg_match('/^---\R(.*?)\R---(?:\R(.*))?$/s', $raw, $m) !== 1) {
      return PageMeta::unmanaged($raw);
    }
    $frontmatter = $m[1];
    $body = ltrim($m[2] ?? '');
    try {
      $decoded = Yaml::decode($frontmatter);
    }
    catch (ExceptionInterface $e) {
      // Malformed YAML: fail-closed only when the page claims provenance.
      // The \s* tolerates "droost :" (legal YAML spacing) — PARITY-CRITICAL
      // with gate-wiki.php's identical textual probe in drup-pipeline: both
      // checkers must attribute a malformed page the same way.
      if (preg_match('/^droost\s*:/m', $frontmatter) === 1) {
        return PageMeta::invalid('frontmatter is not valid YAML: ' . $e->getMessage(), $body);
      }
      return PageMeta::unmanaged($body);
    }
    if (!is_array($decoded)) {
      return PageMeta::unmanaged($body);
    }
    $provenance = NULL;
    $error = NULL;
    if (array_key_exists('droost', $decoded)) {
      try {
        $provenance = Provenance::fromArray($decoded['droost']);
      }
      catch (\InvalidArgumentException $e) {
        $error = $e->getMessage();
      }
    }
    return new PageMeta(
      self::optionalString($decoded['type'] ?? NULL),
      self::optionalString($decoded['title'] ?? NULL),
      self::optionalString($decoded['description'] ?? NULL),
      self::stringList($decoded['tags'] ?? NULL),
      self::optionalString($decoded['timestamp'] ?? NULL),
      self::optionalString($decoded['resource'] ?? NULL),
      $provenance,
      $error,
      $body,
    );
  }

  /**
   * Coerces an OKF scalar to a string or NULL.
   *
   * @param mixed $value
   *   The decoded value.
   *
   * @return string|null
   *   The string form, or NULL when absent/unusable.
   */
  private static function optionalString(mixed $value): ?string {
    if (is_string($value) && trim($value) !== '') {
      return $value;
    }
    // YAML may decode an unquoted timestamp-like scalar to int/float; keep it
    // presentable rather than dropping it.
    if (is_int($value) || is_float($value)) {
      return (string) $value;
    }
    return NULL;
  }

  /**
   * Coerces a tags-style value to a list of strings, dropping junk quietly.
   *
   * OKF metadata keys are presentational; unlike the droost block they are
   * not part of the staleness contract, so lenient coercion is correct here.
   *
   * @param mixed $value
   *   The decoded value.
   *
   * @return array<int, string>
   *   The string entries.
   */
  private static function stringList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $out = [];
    foreach ($value as $item) {
      if (is_string($item) && trim($item) !== '') {
        $out[] = $item;
      }
    }
    return $out;
  }

}
