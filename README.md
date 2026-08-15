# droost/engine

The framework-free engine behind [Droost](https://www.drupal.org/project/droost).

Droost is a developer-acceleration toolkit for AI coding agents working on
Drupal. Most of what it does is not actually Drupal-specific: running a QA
verify loop, writing AI-harness config files, reading a guidelines corpus,
generating scaffolds, composing wiki pages, indexing code. This package is that
half — plain PHP with no Drupal dependency — so the same logic can serve the
Drupal module, a standalone CLI, and repo-only tooling that has no installed
site to boot.

Areas (each depends only on `Support` and `Site`, never on a sibling):

| Area | What it holds | Landed |
| --- | --- | --- |
| `Support` | Shared primitives: project-root discovery, path guards, secret redaction, git HEAD, clock, state store | partly |
| `Site` | What the engine may know about the site it runs against: the extension-locator port and the no-site implementation | yes |
| `Verify` | The QA verify loop (lint, static analysis, tests) and its leg results | yes |
| `Guidelines` / `Skills` | Guidelines corpus reader and skill emitters | yes |
| `Harness` | Installers that write AI-harness files (AGENTS.md, SKILL.md, and friends) | yes |
| `Scaffold` | Blueprint registry and the framework-free code blueprints | no |
| `Wiki` | OKF wiki core: frontmatter, provenance, page composition, staleness | no |
| `Search` | Code search and graph core: chunkers, extractors, indexer, stores | no |

## The `Site` port, and why it is three-valued

`Site\ExtensionLocatorInterface` is how everything else asks what the project
has. Its `isInstalled()` returns `true`, `false`, **or `null`** — because the
engine runs both inside a booted site, where absence is a fact, and against a
bare checkout, where it is not knowable.

That distinction is load-bearing rather than fussy. "Not installed" prunes: it
drops a topic from the guidelines catalog, so it never becomes a skill, so it
never reaches the agent. Letting `null` collapse into `false` would make a
plain checkout silently report half a corpus, and guidance that is missing
looks exactly like guidance that was never written. `Site\UnknownSite` answers
`null` to everything, and callers are contracted to read that as "show it".

## Status

**0.x — extraction in progress.** Code is being lifted out of the droost module
area by area (phases B1 through B5 of the extraction plan); each tranche lands
behind a tagged release before the module switches over to it. Treat the API as
unstable until 1.0: breaking changes bump the minor, and consumers should pin
per minor (`~0.1.0`).

**0.1.1** landed the pilot tranche — `Support\ProjectRoot`, `Support\PathGuard`,
`Support\SecretRedactor`, `Support\GitHead`, `Verify\VerifyRunner`,
`Verify\LegResult` — chosen because it has zero coupling to Drupal and
therefore proved the packaging pipeline on the smallest possible surface.

**0.2.0** adds the guidance tranche (B2): `Site\ExtensionLocatorInterface` and
`Site\UnknownSite`, `Guidelines\GuidelineProvider`, `Skills\Skill`,
`Skills\SkillProvider`, `Skills\SkillMdWriter`, and the whole `Harness` area —
fifteen classes that write AGENTS.md, SKILL.md, and the per-harness config
files for Claude, Codex, Gemini, opencode and Qwen. The minor bumps because
consumers pinned to `~0.1.0` must opt in; nothing in 0.1.1 changed.

The rest of `Support` (clock, state store) arrives with the areas that need it.

## Install

```
composer require droost/engine
```

Requires PHP 8.3 or newer. `ext-pdo_sqlite` is suggested — it backs the
SQLite code-graph and vector stores used for repo-only (no site) operation.

## Development

```
composer install
vendor/bin/phpcs
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

Coding standards are Drupal + DrupalPractice (`phpcs.xml.dist`), matching the
module so lifted files diff to near-zero. PHPStan runs at level max with an
empty baseline. CI runs all four on PHP 8.3 and 8.4.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
