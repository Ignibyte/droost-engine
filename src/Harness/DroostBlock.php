<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

use Droost\Engine\Site\ExtensionLocatorInterface;

/**
 * What droost is, in the one wording every agent-facing surface uses.
 *
 * The AGENTS.md block every harness reads. droost is this codebase's
 * knowledge and the build's runtime: it is asked by command, through drush,
 * and it prescribes no way to build. What it says here is where to look,
 * how to ask, and that the site's own rules are held by gates and a reviewer.
 */
final readonly class DroostBlock {

  /**
   * The directive: look in this codebase before trusting prior knowledge.
   */
  public const string DIRECTIVE = <<<'TXT'
  ## Use Droost first

  Droost knows this codebase first-hand: its index, its symbol graph and its
  wiki are built from this project's own code, core and contrib included.
  When writing, modifying, reviewing, debugging, or planning ANY Drupal or PHP
  code in this project, ask it what THIS project actually contains before
  relying on prior knowledge or walking the file tree — Drupal APIs change
  across versions and model training lags.

  Ask by command. Two of them: `vendor/bin/droost` for the code, which
  boots no Drupal and answers while the site is broken, and `drush
  droost:tool` for the live site. Either lists its tools with no argument,
  shows one with `<id> help`, and runs one with `<id> '<json>'`. On DDEV run
  both in the container: `ddev exec vendor/bin/droost …`, `ddev drush …`.

  - The site's facts and its house rules: `drush droost:brief`.
  - How this project is put together, and how it documents itself: `vendor/bin/droost droost_wiki`.
  - Find real code in this codebase: `vendor/bin/droost` `droost_search`, `droost_symbol`, `droost_graph`.
  - What an installed module gives you: `vendor/bin/droost droost_module_docs`.
  - Verify what actually exists: `drush droost:tool` `droost_services`, `droost_routes`, `droost_entities`, `droost_db_schema`.

  How you build is your call. The site's house rules say what shape the
  result must take, and the run's gates and seeker hold it to them.
  TXT;

  /**
   * Constructs a DroostBlock.
   *
   * @param \Droost\Engine\Site\ExtensionLocatorInterface $site
   *   The site, for the core version the block is stamped with.
   */
  public function __construct(
    private ExtensionLocatorInterface $site,
  ) {}

  /**
   * Returns the AGENTS.md block body (the writer adds the markers).
   *
   * @return string
   *   A version-stamped header, then the directive.
   */
  public function body(): string {
    $version = $this->site->coreVersion();
    $header = $version === ''
      // "Drupal version unknown" is better than omitting the stamp: a reader
      // must be able to tell a block pinned to a version from one that never
      // knew it.
      ? sprintf("# Droost — Drupal version unknown, PHP %s\n\n", PHP_VERSION)
      : sprintf("# Droost — Drupal %s, PHP %s\n\n", $version, PHP_VERSION);
    return $header . self::DIRECTIVE;
  }

}
