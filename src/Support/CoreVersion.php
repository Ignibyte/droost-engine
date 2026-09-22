<?php

declare(strict_types=1);

namespace Droost\Engine\Support;

/**
 * Reads a Drupal core version string.
 *
 * Lived on the guideline provider, which needed it to pick version-branched
 * topics. Four other callers used it for jobs that have nothing to do with
 * guidance — the deprecation ledger, the brain seed, the knowledge ETL and
 * the SKILL.md metadata block — so it outlives the provider.
 */
final class CoreVersion {

  /**
   * Derives the core major from a version string.
   *
   * @param string $version
   *   A version string such as "11.4.2".
   *
   * @return string
   *   The leading numeric segment ("11"), or '' when the version is malformed
   *   or empty — which callers treat as "unknown", never as a version.
   */
  public static function major(string $version): string {
    $major = explode('.', $version)[0];
    return ctype_digit($major) ? $major : '';
  }

}
