<?php

declare(strict_types=1);

namespace Droost\Engine\Support;

use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Exception\ExceptionInterface;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;

/**
 * YAML encode/decode with Drupal's exact settings, minus Drupal.
 *
 * Not a convenience wrapper. Droost writes YAML that is regenerated and
 * compared — wiki pages promise byte-identical output on re-run, and config
 * files land next to Drupal's own — so the formatting is part of the
 * contract, not a detail of it. Symfony's defaults differ from Drupal's in
 * ways that show up immediately: 4-space indent instead of 2, and inlining
 * nested structures past a depth that Drupal never inlines at all. Either one
 * turns a no-op regeneration into a diff.
 *
 * So this replicates \Drupal\Component\Serialization\Yaml precisely: a
 * Dumper(2), an inline level of PHP_INT_MAX, and the same flag pairs on both
 * sides. Verified against core's implementation rather than guessed.
 *
 * Exceptions are left as Symfony's own. Drupal rewraps them as
 * InvalidDataTypeException; there is no such class here, and inventing one
 * would mean callers catching a type that exists only to look familiar.
 */
final class Yaml {

  /**
   * Encodes data as YAML.
   *
   * @param mixed $data
   *   The data to encode.
   *
   * @return string
   *   The YAML.
   *
   * @throws \Symfony\Component\Yaml\Exception\ExceptionInterface
   *   When the data cannot be represented.
   */
  public static function encode(mixed $data): string {
    // Indentation of 2 to match Drupal's coding standards; PHP_INT_MAX as the
    // inline level so nothing is ever collapsed onto one line.
    return (new Dumper(2))->dump(
      $data,
      PHP_INT_MAX,
      0,
      SymfonyYaml::DUMP_EXCEPTION_ON_INVALID_TYPE | SymfonyYaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
    );
  }

  /**
   * Decodes YAML.
   *
   * @param string $raw
   *   The YAML.
   *
   * @return mixed
   *   The decoded data.
   *
   * @throws \Symfony\Component\Yaml\Exception\ExceptionInterface
   *   When the YAML is malformed.
   */
  public static function decode(string $raw): mixed {
    return (new Parser())->parse(
      $raw,
      SymfonyYaml::PARSE_EXCEPTION_ON_INVALID_TYPE | SymfonyYaml::PARSE_CUSTOM_TAGS,
    );
  }

  /**
   * Decodes YAML, returning NULL rather than throwing on malformed input.
   *
   * For the readers that must survive a hand-edited file: a wiki page whose
   * frontmatter someone broke should drop out of the index, not take the
   * whole run down with it.
   *
   * @param string $raw
   *   The YAML.
   *
   * @return mixed
   *   The decoded data, or NULL when it could not be parsed.
   */
  public static function decodeOrNull(string $raw): mixed {
    try {
      return self::decode($raw);
    }
    catch (ExceptionInterface) {
      return NULL;
    }
  }

}
