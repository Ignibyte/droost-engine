# droost/engine

The framework-free engine behind [Droost](https://www.drupal.org/project/droost).

Droost is a developer-acceleration toolkit for AI coding agents working on
Drupal. Most of what it does is not actually Drupal-specific: running a QA
verify loop, writing AI-harness config files, reading a guidelines corpus,
generating scaffolds, composing wiki pages, indexing code. This package is that
half — plain PHP with no Drupal dependency — so the same logic can serve the
Drupal module, a standalone CLI, and repo-only tooling that has no installed
site to boot.

Areas (each depends only on `Support` and `Extension`, never on a sibling):

| Area | What it holds |
| --- | --- |
| `Support` | Shared primitives: project-root discovery, path guards, secret redaction, git HEAD, clock, state store |
| `Extension` | The extension-locator port and its filesystem implementation |
| `Verify` | The QA verify loop (lint, static analysis, tests) and its leg results |
| `Guideline` / `Skill` | Guidelines corpus reader and skill emitters |
| `Harness` | Installers that write AI-harness files (AGENTS.md, SKILL.md, and friends) |
| `Scaffold` | Blueprint registry and the framework-free code blueprints |
| `Wiki` | OKF wiki core: frontmatter, provenance, page composition, staleness |
| `Search` | Code search and graph core: chunkers, extractors, indexer, stores |

## Status

**0.x — extraction in progress.** Code is being lifted out of the droost module
area by area (phases B1 through B5 of the extraction plan); each tranche lands
behind a tagged release before the module switches over to it. Treat the API as
unstable until 1.0: breaking changes bump the minor, and consumers should pin
per minor (`~0.1.0`).

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
