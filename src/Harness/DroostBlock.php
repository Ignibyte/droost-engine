<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

use Droost\Engine\Site\ExtensionLocatorInterface;

/**
 * What droost is, in the one wording every agent-facing surface uses.
 *
 * The AGENTS.md block every harness reads. droost is this codebase's
 * knowledge and the build's runtime: one command, `droost`, reading files
 * with no Drupal (2026-10-19), and it prescribes no way to build. The same
 * text as droost/workflow's HarnessSetup::DIRECTIVE, which writes it. What it says here is where to look,
 * how to ask, and that the site's own rules are held by gates and a reviewer.
 */
final readonly class DroostBlock {

  /**
   * The directive: look in this codebase before trusting prior knowledge.
   */
  public const string DIRECTIVE = <<<'TXT'
  ## Use droost first

  droost knows this codebase first-hand: its code index, its structure and
  its wiki are built from this project's own files, core and contrib
  included. Before writing, changing, reviewing or planning any Drupal or
  PHP code here, ask it what THIS project contains rather than trusting
  prior knowledge: Drupal's APIs change between versions. droost reads files
  only, so it answers while the site is broken.

  - `droost code:wiki` — the wiki, and an inventory of every installed module
    and theme (`droost code:wiki inventory`, `droost code:wiki inventory/<name>`).
  - `droost code:tree [module|path]` — the code's structure: files, classes,
    methods, and each module's hooks, services and routes.
  - `droost search:arch "<words>"` — the documentation, as plain text.
  - `droost code:symbol`, `droost code:graph`, `droost search:code`,
    `droost code:docs`, `droost code:deprecations`, `droost brief`.

  `droost` with no argument lists the commands; `droost <command> help`
  explains one. Plan consults `code:wiki` and `code:tree` before it closes.
  When the code index is missing or stale, `droost code:index` rebuilds it.

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
