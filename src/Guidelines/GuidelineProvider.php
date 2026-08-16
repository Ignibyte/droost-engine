<?php

declare(strict_types=1);

namespace Droost\Engine\Guidelines;

use Droost\Engine\Site\ExtensionLocatorInterface;

/**
 * Reads and composes the shipped AI guidelines corpus.
 *
 * Guidelines live as Markdown under a `guidelines/` directory: `core/` files
 * are always-on conventions; `topics/` files are on-demand deep-dives an agent
 * can pull by name.
 *
 * The catalog is scoped to the site rather than to the corpus. A topic whose
 * subject is a module the site does not have is left OUT of the listing — the
 * catalog answers "what can I act on here", and offering media guidance to a
 * site with no media module advertises a capability that is not there. The
 * same topic asked for BY NAME is still served, because an agent may be
 * deciding whether to install it; it is prefixed with the fact instead, so the
 * content cannot be read as a description of this site.
 *
 * That distinction is why the site is reached through
 * ExtensionLocatorInterface rather than a bool: "not installed" prunes, but
 * "cannot tell" must not. See the interface for why.
 */
final class GuidelineProvider {

  /**
   * The imperative "consult Droost first" directive (the brain-layer trigger).
   *
   * Single source of truth, emitted to every guidance surface (the AGENTS.md
   * block, the droost_guidelines tool, and the MCP server `instructions` field
   * published at the initialize handshake) so the agent reaches for Droost
   * before relying on its own (version-stale) Drupal knowledge.
   */
  public const string BRAIN_DIRECTIVE = <<<'TXT'
  ## Use Droost first

  When writing, modifying, reviewing, debugging, or planning ANY Drupal or PHP
  code in this project — modules, themes, plugins, services, hooks, entities,
  forms, routes, config, tests, or migrations — consult Droost BEFORE relying on
  prior knowledge. Drupal APIs change across versions and model training lags.

  - Conventions and deep-dives: call `droost_guidelines` (pass a `topic`).
  - Find real code in THIS codebase: `droost_search`, `droost_symbol`, `droost_graph`.
  - Exact installed contrib/core APIs: `droost_module_docs`.
  - Verify what actually exists: `droost_services`, `droost_routes`, `droost_entities`, `droost_db_schema`.

  Never call `\Drupal::` in a class that supports dependency injection without
  checking the DI guidance first.
  TXT;

  /**
   * The shipped topics that only apply when a given module is installed.
   *
   * Contributed topics are presence-gated by construction — topicDirs() walks
   * INSTALLED extensions only — but the shipped corpus travels whole, so these
   * need the same treatment explicitly. Without it, a site with no media
   * module is told how to work with media, which reads as a statement about
   * this site and is not one.
   *
   * Keyed by topic machine name; the value is the module that must be
   * installed. Only topics whose subject IS a module belong here: a topic
   * about routing or cacheability applies to every site.
   */
  private const array TOPIC_REQUIRES = [
    'media' => 'media',
    'taxonomy' => 'taxonomy',
    'workflows' => 'content_moderation',
    'multilingual' => 'language',
    'jsonapi' => 'jsonapi',
    'migrate' => 'migrate',
  ];

  /**
   * Matches a topic's own requirement declaration.
   *
   * A topic may name the module it describes on its first line:
   *
   * @code
   * <!-- droost:requires eca -->
   * # ECA: ...
   * @endcode
   *
   * The map above only reaches topics this package ships, which made the
   * site-fidelity rule unextendable: any module can drop a topic into
   * `guidelines/topics/`, but gating it needed an edit and a release HERE.
   * A topic about a contrib module could therefore only ship ungated, telling
   * every site how to use something it may not have — the exact thing the map
   * exists to prevent. Declaring it in the file keeps the fact next to the
   * content it qualifies, and the seam open to everyone.
   *
   * An HTML comment rather than YAML frontmatter: it needs no parser, it is
   * invisible wherever the Markdown is rendered, and a topic that predates
   * this simply has none.
   */
  private const string REQUIRES_PATTERN = '/\A<!--\s*droost:requires\s+([a-z0-9_]+)\s*-->\s*\R/';

  /**
   * Constructs a GuidelineProvider.
   *
   * @param string $appRoot
   *   The application root that extension paths are relative to.
   * @param string $guidelinesDir
   *   Absolute path to the shipped `guidelines/` directory (holding `core/`
   *   and `topics/`). Passed in rather than located, so the corpus can ship
   *   wherever its owner ships.
   * @param \Droost\Engine\Site\ExtensionLocatorInterface $site
   *   The site to scope the catalog to. Every INSTALLED extension's
   *   `guidelines/topics/*.md` files join the catalog — the seam that
   *   site-specific and third-party modules publish through.
   */
  public function __construct(
    private readonly string $appRoot,
    private readonly string $guidelinesDir,
    private readonly ExtensionLocatorInterface $site,
  ) {}

  /**
   * Derives the core major from a version string.
   *
   * @param string $version
   *   A version string such as "11.4.2".
   *
   * @return string
   *   The leading numeric segment ("11"), or '' when the version is malformed
   *   or empty (which disables version-branched topic resolution).
   */
  public static function deriveMajor(string $version): string {
    $major = explode('.', $version)[0];
    return ctype_digit($major) ? $major : '';
  }

  /**
   * Returns the application root.
   *
   * @return string
   *   The app root path.
   */
  public function appRoot(): string {
    return $this->appRoot;
  }

  /**
   * Returns the composed always-on core guidelines.
   *
   * @return string
   *   The concatenated core guideline Markdown.
   */
  public function getCore(): string {
    return $this->readAll($this->guidelinesDir . '/core');
  }

  /**
   * Returns the core guidelines prefixed with a version-stamped header.
   *
   * Single source of truth for the stamped form, used by both the
   * droost_guidelines tool and the AGENTS.md writer so they stay identical.
   *
   * @return string
   *   The version-stamped core guideline Markdown.
   */
  public function getCoreStamped(): string {
    $version = $this->site->coreVersion();
    $header = $version === ''
      // Saying "Drupal (unknown)" is better than omitting the stamp: a reader
      // must be able to tell guidance that was pinned to a version from
      // guidance that never knew one.
      ? sprintf("# Droost guidelines — Drupal version unknown, PHP %s\n\n", PHP_VERSION)
      : sprintf("# Droost guidelines — Drupal %s, PHP %s\n\n", $version, PHP_VERSION);
    return $header . self::BRAIN_DIRECTIVE . "\n\n" . $this->getCore();
  }

  /**
   * Returns the brain-layer "use Droost first" directive.
   *
   * @return string
   *   The directive Markdown.
   */
  public function getBrainDirective(): string {
    return self::BRAIN_DIRECTIVE;
  }

  /**
   * Returns the AGENTS.md managed-block body (markers added by the writer).
   *
   * Single source of truth for the block content, used by the AGENTS.md
   * installer so it stays identical to what the droost_guidelines tool serves.
   *
   * @return string
   *   The block body: directive + stamped core + topic list.
   */
  public function getGuidelinesBlockBody(): string {
    $topics = array_map(
      static fn(array $topic): string => '- `' . $topic['name'] . '` — ' . $topic['summary'],
      $this->listTopics(),
    );
    return $this->getCoreStamped() . "\n\n"
      . "## Deep-dive topics (call the `droost_guidelines` MCP tool with `topic`)\n\n"
      . implode("\n", $topics);
  }

  /**
   * Lists the available on-demand topics.
   *
   * @return array<int, array{name: string, summary: string}>
   *   Each topic's machine name and one-line summary.
   */
  public function listTopics(): array {
    $topics = [];
    foreach ($this->topicDirs() as $dir) {
      foreach ($this->files($dir) as $name => $path) {
        if (!isset($topics[$name]) && $this->topicApplies($name, $path)) {
          $topics[$name] = ['name' => $name, 'summary' => $this->summary($path)];
        }
      }
    }
    ksort($topics);
    return array_values($topics);
  }

  /**
   * Returns one topic's content.
   *
   * A topic asked for BY NAME is served even when its subject module is
   * absent — an agent may be deciding whether to install it, and the guidance
   * is true Drupal guidance either way. What must not happen is the content
   * reading as a description of this site, so it is prefixed with the fact.
   *
   * @param string $name
   *   The topic machine name.
   *
   * @return string|null
   *   The Markdown, or NULL if the topic does not exist.
   */
  public function getTopic(string $name): ?string {
    $name = preg_replace('/[^a-z0-9_-]/', '', strtolower($name)) ?? '';
    if ($name === '') {
      return NULL;
    }
    foreach ($this->topicDirs() as $dir) {
      $path = $dir . '/' . $name . '.md';
      if (is_file($path)) {
        $body = $this->stripRequires((string) file_get_contents($path));
        if ($this->topicApplies($name, $path)) {
          return $body;
        }
        return sprintf(
          "> NOT INSTALLED ON THIS SITE: the %s module is not enabled here, so nothing below describes this project's current state. Install it first, or treat this as background reading.\n\n%s",
          (string) $this->topicRequires($name, $path),
          $body,
        );
      }
    }
    return NULL;
  }

  /**
   * Whether a topic's subject module is installed on this site.
   *
   * Unmapped topics always apply. So do mapped ones when the site cannot be
   * asked: "I cannot tell" must not silently become "not installed", which
   * would hide guidance rather than scope it.
   *
   * @param string $name
   *   The topic machine name.
   * @param string|null $path
   *   The topic file, when the caller already resolved it — so the topic's
   *   own declaration can be read. NULL falls back to the shipped map.
   *
   * @return bool
   *   TRUE when the topic applies to this site.
   */
  private function topicApplies(string $name, ?string $path = NULL): bool {
    $required = $this->topicRequires($name, $path);
    if ($required === NULL) {
      return TRUE;
    }
    // Only an explicit FALSE prunes. NULL is unknown, and unknown shows.
    return $this->site->isInstalled($required) !== FALSE;
  }

  /**
   * The module a topic describes, if it declares or is mapped to one.
   *
   * The topic's own `<!-- droost:requires ... -->` line wins, so a module
   * shipping a topic about itself needs no change here. The shipped map is
   * the fallback for the topics that predate the declaration.
   *
   * @param string $name
   *   The topic machine name.
   * @param string|null $path
   *   The topic file, when the caller already resolved it. Omitted callers
   *   fall back to the shipped map alone rather than re-reading the corpus.
   *
   * @return string|null
   *   The required module, or NULL when the topic applies everywhere.
   */
  private function topicRequires(string $name, ?string $path = NULL): ?string {
    if ($path !== NULL && is_file($path)) {
      $head = (string) file_get_contents($path, FALSE, NULL, 0, 256);
      if (preg_match(self::REQUIRES_PATTERN, $head, $matches) === 1) {
        return $matches[1];
      }
    }
    return self::TOPIC_REQUIRES[$name] ?? NULL;
  }

  /**
   * Strips a topic's requirement declaration from what gets served.
   *
   * The marker is metadata about the topic, not part of it. Leaving it in
   * would put a machine-readable directive into an agent's context, where the
   * best case is that it is ignored.
   *
   * @param string $body
   *   The raw topic Markdown.
   *
   * @return string
   *   The body without its declaration line.
   */
  private function stripRequires(string $body): string {
    return preg_replace(self::REQUIRES_PATTERN, '', $body) ?? $body;
  }

  /**
   * Lists every directory that may contribute topics.
   *
   * The shipped topics come first (they win name collisions); then every
   * installed extension that ships a `guidelines/topics` directory, in name
   * order. Directories already listed are skipped, so an extension that IS
   * the corpus owner does not contribute itself twice.
   *
   * @return array<int, string>
   *   Absolute topic-directory paths.
   */
  private function topicDirs(): array {
    $dirs = $this->existingDirs($this->guidelinesDir . '/topics');
    $seen = array_flip($dirs);
    $extensions = $this->site->installed();
    sort($extensions);
    foreach ($extensions as $extension) {
      $path = $this->site->path($extension);
      if ($path === NULL) {
        // An extension can be installed yet unresolvable on disk (its
        // directory removed while still in core.extension). Skip it rather
        // than fatally aborting topic discovery.
        continue;
      }
      foreach ($this->existingDirs($this->appRoot . '/' . $path . '/guidelines/topics') as $dir) {
        if (!isset($seen[$dir])) {
          $seen[$dir] = TRUE;
          $dirs[] = $dir;
        }
      }
    }
    return $dirs;
  }

  /**
   * Expands a topics directory into its existing version-then-base candidates.
   *
   * With a resolved core major, the per-major variant directory is searched
   * FIRST (so its files win the first-match-wins resolution) and the
   * unversioned directory second; without one, only the unversioned directory.
   * Non-existent candidates are dropped, so a corpus with no version dirs
   * resolves byte-identically.
   *
   * @param string $topicsDir
   *   The unversioned topics directory.
   *
   * @return array<int, string>
   *   The existing candidate directories, most-specific first.
   */
  private function existingDirs(string $topicsDir): array {
    $major = self::deriveMajor($this->site->coreVersion());
    $candidates = $major === ''
      ? [$topicsDir]
      : [$topicsDir . '/' . $major, $topicsDir];
    return array_values(array_filter($candidates, 'is_dir'));
  }

  /**
   * Lists Markdown files in a directory, keyed by machine name.
   *
   * @param string $dir
   *   The directory.
   *
   * @return array<string, string>
   *   Map of base name (without extension) to absolute path, sorted by name.
   */
  private function files(string $dir): array {
    $out = [];
    foreach (glob($dir . '/*.md') ?: [] as $path) {
      $out[basename($path, '.md')] = $path;
    }
    ksort($out);
    return $out;
  }

  /**
   * Concatenates every Markdown file in a directory.
   *
   * @param string $dir
   *   The directory.
   *
   * @return string
   *   The concatenated content.
   */
  private function readAll(string $dir): string {
    $parts = [];
    foreach ($this->files($dir) as $path) {
      $parts[] = (string) file_get_contents($path);
    }
    return implode("\n\n", $parts);
  }

  /**
   * Extracts the first non-heading line of a file as its summary.
   *
   * @param string $path
   *   The file path.
   *
   * @return string
   *   A one-line summary, capped at 160 characters.
   */
  private function summary(string $path): string {
    // Strip the requirement declaration first: it is not a heading, so the
    // loop below would otherwise return the marker itself as the summary and
    // the catalog would advertise every gated topic as "<!-- droost:requires
    // … -->".
    $content = $this->stripRequires((string) file_get_contents($path));
    foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
      $line = trim($line);
      if ($line !== '' && !str_starts_with($line, '#')) {
        return mb_substr($line, 0, 160);
      }
    }
    return '';
  }

}
