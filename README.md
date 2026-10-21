# droost/engine

The framework-free engine behind droost.

droost is one command, `droost` (shipped by `droost/workflow`), for an AI
coding agent working on a Drupal site: what the codebase holds, and the
gated run the work goes through. It reads files and needs no Drupal; since
2026-10-19 it is no longer a Drupal module. This package is its engine —
plain PHP with no Drupal dependency: the code index and its SQLite stores,
the wiki and search cores, the harness installers that write AGENTS.md and
each agent's files — used by the `droost` command and by repo-only tooling
(`bin/droost-index`).

Areas (each depends only on `Support` and `Site`, never on a sibling, except
`Harness`, whose Claude installer writes `Skills`' files):

| Area | What it holds | Landed |
| --- | --- | --- |
| `Support` | Shared primitives: path guards, core version, YAML | yes |
| `Site` | What the engine may know about the site it runs against (its core version), and who owns an extension's code | yes |
| `Skills` | Skill emitters: droost's own skill files rendered as SKILL.md | yes |
| `Harness` | Installers that write AI-harness files (AGENTS.md, SKILL.md, and friends) and remove the MCP entry an earlier droost wrote | yes |
| `Wiki` | OKF wiki core: frontmatter, provenance, file hashing, bundle reading | yes |
| `Search` | Code search and graph core: chunkers, extractors, index diffing, the SQLite stores droost's code index lives in, and the repo-only indexer (`bin/droost-index`) | yes |

## The `Site` port

`Site\ExtensionLocatorInterface` is what the engine may ask the site, and all
it asks is the core version it stamps content with (`coreVersion()`; the
port's `installed()` and `path()` went in 0.11.0). The `droost` command boots
no site, so a locator that cannot ask one (`Site\UnknownSite`) answers `''`,
which means "cannot tell", never a guess.

## Status

**0.x.** The code was lifted out of the droost Drupal module area by area
(phases B1 through B5 of the extraction plan); the module is now retired, and
the consumer is `droost/workflow`'s `droost` command. Treat the API as
unstable until 1.0: breaking changes bump the minor, and consumers should pin
per minor (`~0.1.0`). The entries below are the record as each release
shipped.

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

**0.3.0** adds the scaffold spine and the eight template-only blueprints (B3),
(the Drush-backed half stayed in the module; this half left on 2026-10-21,
see Unreleased).

**0.4.0** adds the OKF wiki core (B4) — frontmatter parsing, provenance,
page composition, bundle reading — plus `Support\Yaml`, which reproduces
Drupal's YAML settings exactly (`Dumper(2)`, inline level `PHP_INT_MAX`, the
same flag pairs) so a regenerated page is byte-identical to the last one. That
was verified against core's own serializer on a booted site, not assumed;
`Wiki\BundleLocatorInterface` is the one port it needs, and the module's
existing `WikiSettings` already satisfies it unchanged.

**0.5.0** adds the pure search core (B5a): the chunkers, `Graph\GraphVisitor`
and `Graph\YamlGraphExtractor`, index diffing, and the embedding and
vector-store interfaces. The storage layer stays in the module for now — four
classes built on Drupal's database API, which need a port of their own before
they can move (B5b).

**0.6.x** lands B5b: the three storage ports (file manifest, code graph,
vector store), each with a SQLite implementation. They are what
`bin/droost-index` runs on, so it can index a repository and answer
code-graph questions with no Drupal site at all. The Drupal indexer stays in
the module, because it reads site configuration a bare checkout does not
have. The line also added:
- the recipe, migrate, plugin-deriver, media-source, ckeditor5-plugin and
  functional-test blueprints;
- metadata filters on the vector-store port, applied before the k-limit;
- slash-command materialization for the Claude installer (`CommandProvider`,
  with nested command names).

It also let a guideline topic name the module it describes, which 0.7.0 took
out again with the rest.

**0.7.0** removes the guidance tranche. `Guidelines\GuidelineProvider` is gone
— its one non-guidance job, `deriveMajor()`, is now `Support\CoreVersion::major()`
— and `Skills\SkillProvider` lists only the consumer's own skill files instead
of rendering every guideline topic as a `drupal-*` skill. The harness writes the
AGENTS.md block (`Harness\DroostBlock`, what droost is) and every editor's
pointer to it unconditionally: `InstallContext` loses `guidelinesMode`, because
those files are also how a build pipeline's own AGENTS.md block reaches the
agent, and a switch that could cut that road silently is not one worth keeping.
The Claude installer now sweeps retired droost skills — only directories it
provably wrote and nobody has touched since — and its sentinel records the
SKILL.md hash so later sweeps can keep an edited skill. Breaking, hence the
minor.

**0.7.2** records only unconditional declarations as code-graph symbols. A
class or function declared inside an `if`, a loop or a function body exists
only once that code has run, and is usually a fallback for the real one:
project_browser's fixture script declares `class Drupal` when
`!class_exists('Drupal')`. With core unindexed, that stand-in owned every
`\Drupal::` call in a codebase, so the wiki put project_browser in scope for
every custom module that calls `\Drupal`. `PhpGraphExtractor` now runs
`ParentConnectingVisitor`, which `GraphVisitor` expects.

**0.7.3** narrows that rule to what it was for. Only a declaration guarded by
its own absence, `if (!class_exists('X')) { class X … }` (or the interface,
trait, enum or function form), is skipped. 0.7.2 skipped every conditional
declaration, and a full rebuild of a real site showed the cost: webform's
`WebformManagedFileBase`, declared in both branches of a feature check, and a
theme's preprocess hook, declared only while a module is on, dropped out of
the graph although each is the only declaration of its name.
`RepoIndexer::INDEXER_VERSION` is 2, so a repository index rebuilds itself.

**0.7.4** fixes two blueprints. The CKEditor 5 plugin's declaration no longer
names a `library` or `admin_library` the blueprint never writes, and the MCP
tool blueprint implements the `doExecute()` hook its base class calls rather
than overriding the final `execute()`.

**0.7.5** adds `Site\ScopeClassifier` and `Site\Provenance`: one answer to who
owns an extension's code, where droost had five that disagreed. Composer's
runtime record decides first (`drupal-core` is core, `drupal-custom-*` custom,
any other `drupal-*` package contrib, the deepest install directory winning),
then the first path segment named `contrib` or `custom`, and code placed by
hand is the project's own. A package installed at or above the docroot owns
nothing, so a module's own CI checkout does not make every extension contrib.
Paths are compared as written, never resolved, so a package composer linked
in from elsewhere is still found at its link.

**Unreleased**:
- The retired module's code is gone (2026-10-21; its runs on the owner's
  sites were tests, so nothing reads its shape): the `Scaffold` area and
  its thirteen blueprints, the `Verify` loop (and the `symfony/process` and
  `psr/log` requirements), `HarnessRegistry`, `DroostBlock` (droost/workflow's
  `HarnessSetup` writes the block), `CommandProvider`, the wiki's
  `PageComposer`, `PageRenderer`, `GenerationRow` and `ComposeException`, and
  `Support\GitHead`, `SecretRedactor` and `ProjectRoot`.
- The harnesses write no MCP server: `InstallContext` carries the block body
  only, and every install removes the entry an earlier droost wrote, as
  uninstall does. `Toml`, `JsonMerge::setPath()` and `mergePath()` are gone;
  `Markers::TOML_*` stay, to find Codex's old region.
- droost leaves Drupal (2026-10-19): the directive names the `droost`
  command's knowledge commands (`code:wiki`, `code:tree`, `search:arch`, …)
  instead of drush and MCP.
- A call on `$this` (or `$this?->`), `self::` or `static::` is a `calls` edge
  to the method it names, so a protected helper called only through `$this`
  no longer reads as having no callers (F-322). A call on any other object
  stays unrecorded: its class cannot be known statically.
  `RepoIndexer::INDEXER_VERSION` is 4, so an existing index rebuilds.

**0.11.1** — symbol and edge queries list a site's own rows before core's
(F-297). A site's index may carry core's symbols, and ordered by name alone
`Drupal\Core\…` filled a limited search before the site's own class was
reached.

**0.11.0** — the seekers' cleanup:
- An install or uninstall keeps every JSON object the user had as an object
  (another server's `"env": {}` was rewritten as `[]`), keeps the keys a user
  added to droost's own entry (`env`, a timeout), and leaves a config that
  already says what droost would write untouched, formatting and all.
- Codex: a droost server the user wrote in any TOML spelling (quoted,
  spaced, a sub-table, a dotted key, a key under `[mcp_servers]`) is left
  alone rather than defined twice, and every control character in a string
  is escaped.
- Uninstall removes a droost skill only as droost wrote it: a user's file
  beside it, an edited SKILL.md or a symlink keeps the directory, with a
  warning. A skill's frontmatter name must be a plain name.
- The SQLite stores: the fan summary is ordered before its cap; a vector or
  meta that will not encode rolls its batch back (invalid UTF-8 in meta is
  substituted); a write re-makes a table another process dropped. The
  unused `open()` factories are gone.
- `bin/droost-index`: a query (`--find`, `--callers`, `--fan`) refuses a
  missing index and opens it read-only, and an index an older extractor wrote
  (`RepoIndexer::INDEXER_VERSION`, kept as SQLite's `user_version`) is
  rebuilt whole. `RepoIndexer` no longer chunks files only to count chunks.
- Removed as unused: `EngineInfo`, `InstallResult::addSkipped()`, and the
  locator port's `installed()` and `path()` (only `coreVersion()` is asked).

**0.10.0** — droost's MCP server comes back as a second door, a thin
server that holds no Drupal and runs each call as a fresh command.
`HarnessRegistry::install(string $root, string $harness, ?array $server = NULL)`:
with a server (`['command' => …, 'args' => […]]`) every harness writes droost's
entry beside its other servers (`.mcp.json`, `.gemini/` and `.qwen/` settings
under `mcpServers`; a fenced `[mcp_servers.droost]` table in
`.codex/config.toml`; a `local` entry in `opencode.json`); with NULL the entry
is removed, as in 0.8.0. `InstallContext` carries the optional server, and
`Toml` and `JsonMerge::setPath` are back. The directive says droost's tools
answer through MCP or by command, with the same record.

**0.9.0** — the SQLite stores order edges before a limit, throw on a row
that is not values (the rebuild rolls back instead of storing `''`), and ask
whether a table exists every time, since droost's code index is a file other
processes share. The AGENTS block names both commands: `vendor/bin/droost`
for the code, `drush droost:tool` for the live site. Dead code removed:
`JsonMerge::setPath` (back in 0.10.0), `InstallResult::merge()`,
`ScopeClassifier::knowsComposer()`, `Yaml::decodeOrNull()` and the locator
port's `isInstalled()`.

**0.8.0** — droost is reached through drush, not MCP. The harness
installers write guidance only (AGENTS.md, and each editor's pointer to it)
and remove the droost MCP server entry an earlier install registered in
`.mcp.json`, `.gemini/` and `.qwen/` settings, `.codex/config.toml` and
`opencode.json`, keeping every other server. `HarnessRegistry::install()`
takes the root and the harness selection only, and `InstallContext` carries
only the block body. The directive tells an agent to ask with `drush
droost:tool` and `drush droost:brief`, and that how to build is its call,
held to the site's house rules by the run's gates and seeker. `Toml` is
gone with the server table it wrote.

**0.7.9** rewrites the directive every agent reads (AGENTS.md and the MCP
handshake): droost knows this codebase first-hand, so put the plan to it
(`droost_consult`), ask it while coding instead of walking the tree, and
read its answers as advice.

**0.7.8** fixes the hook blueprint: it scaffolded a second implementation
of a hook core invokes for one module at a time (a preprocess hook,
`theme`, `help`, `mail`) in a module that already had one, and the first
call threw core's "should not implement … more than once". It now refuses,
and names the implementation to add to.

**0.7.7** fixes the migrate blueprint: its process plugin typed
`transform()`'s `$destination_property`, which core leaves untyped, and PHP
refused every class it generated; its migration now carries
`migration_tags`, which core and drush 13 read, instead of migrate_plus's
`migration_group`.

**0.7.6** is the wiki at scale:
- `Wiki\PageRenderer` writes a page body from a factsheet alone, with no
  model. The same factsheet renders the same bytes, and a section nobody
  could measure says why.
- `GenerationRow::unchanged()` reports a regeneration that would write what a
  page already says.
- `PageComposer::compose()` records which writer made the body
  (`droost:wiki:generate`, `droost:wiki:write` or `droost:wiki:render`) and
  types a theme's page as a `Drupal Theme`.

It also has two fixes:
- A hook an api.php documents is a `hook:NAME` symbol owned by its module,
  so an implementation's edge reaches a module rather than nothing.
  `RepoIndexer::INDEXER_VERSION` is 3.
- The kernel-test and functional-test blueprints keep a class name that
  already ends in `Test`.

## Install

```
composer require droost/engine
```

Requires PHP 8.3 or newer. `ext-pdo_sqlite` is suggested — it backs the
SQLite stores: droost's code index on a site, and `bin/droost-index` on a
repository with none.

## Development

```
composer install
vendor/bin/phpcs
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpunit
```

Coding standards are Drupal + DrupalPractice (`phpcs.xml.dist`), the same as
`droost/workflow`'s. PHPStan runs at level max with an empty baseline; the tree
outgrew its 128M default memory. CI runs `composer validate --strict` and
these three on PHP 8.3 and 8.4.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
