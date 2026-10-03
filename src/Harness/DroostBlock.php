<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

use Droost\Engine\Site\ExtensionLocatorInterface;

/**
 * What droost is, in the one wording every agent-facing surface uses.
 *
 * One source, two readers: the AGENTS.md block the harness writes, and the
 * MCP server `instructions` published at the initialize handshake. It used to
 * be the guideline provider's brain directive, stapled to a conventions
 * corpus and a topic catalogue. Droost no longer ships guidance — agents never
 * reached for it — so what remains is the part that was always true: droost
 * is the brain of THIS codebase, and it should be asked before a model's
 * training is trusted.
 */
final readonly class DroostBlock {

  /**
   * The directive: consult this codebase before prior knowledge.
   */
  public const string DIRECTIVE = <<<'TXT'
  ## Use Droost first

  Droost is the brain of this codebase, and it knows it first-hand: its
  index, its symbol graph and its wiki are built from this project's own
  code, core and contrib included. When writing, modifying, reviewing,
  debugging, or planning ANY Drupal or PHP code in this project, ask it what
  THIS project actually contains before relying on prior knowledge or walking
  the file tree — Drupal APIs change across versions and model training lags.

  - Put your plan to it: `droost_consult` reads the spec and answers each construct and page with what droost believes is good Drupal practice here.
  - What builds a kind of thing on this site (a generator, a blueprint, a droost tool): `droost_decide`.
  - How this project is put together, and how it documents itself: `droost_wiki`.
  - Find real code in this codebase: `droost_search`, `droost_symbol`, `droost_graph`.
  - What an installed module gives you: `droost_module_docs`.
  - Verify what actually exists: `droost_services`, `droost_routes`, `droost_entities`, `droost_db_schema`.

  Ask while you code as much as while you plan. Its answers are advice: you
  choose how to build, and the record shows both.
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
