<?php

declare(strict_types=1);

namespace Droost\Engine\Wiki;

use Droost\Engine\Support\Yaml;
use Droost\Engine\Wiki\Okf\FrontmatterParser;

/**
 * Composes a droost-managed OKF wiki page from a factsheet + a body.
 *
 * The truth-authoring half of the self-generation driver: the LLM (or a
 * --body-file) supplies ONLY the prose body; this composer writes every
 * provenance fact — the source hashes, module list, spec, and commit — from
 * the factsheet and the caller-supplied commit, and STRIPS any frontmatter the
 * body carried. A hallucinated provenance block is therefore structurally
 * impossible. The assembled page is validated in-memory against the real
 * FrontmatterParser + Provenance contract before it is returned, so the writer
 * never emits a page the freshness checker would call "invalid".
 */
final readonly class PageComposer {

  /**
   * The generator identity stamped into every composed page.
   */
  public const string GENERATOR = 'droost:wiki:generate';

  /**
   * Maximum provenance sources kept on a page.
   *
   * The factsheet hands over the module's FULL inventory; tracking all of it
   * marks the page stale on every unrelated commit (the factsheet's own
   * guidance). Identity/interface files are kept first, then filled to this
   * cap — enough to prove grounding, few enough to stay stable.
   */
  public const int MAX_SOURCES = 6;

  /**
   * Constructs a PageComposer.
   *
   * @param \Droost\Engine\Wiki\Okf\FrontmatterParser $parser
   *   The frontmatter parser, used to validate the composed page in-memory.
   */
  public function __construct(
    private FrontmatterParser $parser,
  ) {}

  /**
   * Composes a validated OKF page for one module.
   *
   * @param string $module
   *   The module machine name.
   * @param array<string, mixed> $factsheet
   *   The FactsheetBuilder::build() packet for $module.
   * @param string $body
   *   The page body (any leading frontmatter fence is stripped).
   * @param string $commit
   *   The git HEAD at generation time ('' when unknown); metadata only.
   * @param string|null $timestamp
   *   The generation timestamp (RFC 3339), or NULL to omit it. Passed in so the
   *   composer stays pure and deterministic.
   *
   * @return string
   *   The full page text: an OKF frontmatter fence followed by the body.
   *
   * @throws \Droost\Engine\Wiki\ComposeException
   *   When the factsheet lacks the provenance template, no source survives
   *   selection, or the assembled page fails the real parse/validate contract.
   */
  public function compose(string $module, array $factsheet, string $body, string $commit, ?string $timestamp = NULL): string {
    $template = $factsheet['provenance_template'] ?? NULL;
    if (!is_array($template)) {
      throw new ComposeException(sprintf('factsheet for "%s" has no provenance_template', $module));
    }
    $sources = self::selectSources($template['sources'] ?? NULL, $module, $this->primaryDoc($factsheet));
    if ($sources === []) {
      throw new ComposeException(sprintf('factsheet for "%s" lists no usable sources', $module));
    }

    $identity = is_array($factsheet['identity'] ?? NULL) ? $factsheet['identity'] : [];
    $label = $this->scalarString($identity['label'] ?? NULL) ?? $module;
    $description = $this->scalarString($identity['description'] ?? NULL)
      ?? sprintf('Droost wiki page for the %s module.', $module);

    $frontmatter = [
      'type' => 'Drupal Module',
      'title' => $label === $module ? $module : sprintf('%s (%s)', $label, $module),
      'description' => $description,
      'tags' => [$module],
    ];
    if ($timestamp !== NULL) {
      $frontmatter['timestamp'] = $timestamp;
    }
    $frontmatter['droost'] = [
      'spec' => 1,
      'modules' => [$module],
      'sources' => $sources,
      'generated_commit' => $commit,
      'generator' => self::GENERATOR,
      'queries' => $this->queries($template),
    ];

    $page = "---\n" . Yaml::encode($frontmatter) . "---\n\n" . self::stripFrontmatter($body);
    $this->validate($page);
    return $page;
  }

  /**
   * Selects the provenance sources to record on the page (resolves R2).
   *
   * Identity/interface files first (info/services/routing/module/install and
   * the primary doc), then the rest, capped at MAX_SOURCES — deterministic and
   * stable. Entries already carry the factsheet's project-root-relative path +
   * algo-prefixed hash, so the selection validates by construction.
   *
   * @param mixed $sources
   *   The factsheet provenance_template.sources (a list of {path, hash}).
   * @param string $module
   *   The module machine name (for identity-file matching).
   * @param string|null $primaryDoc
   *   The factsheet's primary doc path, kept preferentially when present.
   *
   * @return array<int, array{path: string, hash: string}>
   *   The selected sources (empty when the input has none usable).
   */
  private static function selectSources(mixed $sources, string $module, ?string $primaryDoc): array {
    if (!is_array($sources)) {
      return [];
    }
    $names = [
      $module . '.info.yml',
      $module . '.services.yml',
      $module . '.routing.yml',
      $module . '.module',
      $module . '.install',
    ];
    $identity = [];
    $srcCode = [];
    $rest = [];
    foreach ($sources as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $path = $entry['path'] ?? NULL;
      $hash = $entry['hash'] ?? NULL;
      if (!is_string($path) || !is_string($hash)) {
        continue;
      }
      $clean = ['path' => $path, 'hash' => $hash];
      // $path is project-root-relative but $primaryDoc is module-relative
      // (ModuleDocReader returns a bare name), so compare by suffix, not
      // equality — otherwise the primary-doc preference never fires.
      if (in_array(basename($path), $names, TRUE) || ($primaryDoc !== NULL && ($path === $primaryDoc || str_ends_with($path, '/' . $primaryDoc)))) {
        $identity[] = $clean;
      }
      elseif (preg_match('#(^|/)src/.+\.php$#', $path) === 1) {
        // Prefer the module's own class files for the remaining slots: a page
        // that records only config/docs (which sort before src/ in the
        // inventory) never goes stale when the code changes, making the
        // freshness gate decorative for exactly the driver's own output.
        $srcCode[] = $clean;
      }
      else {
        $rest[] = $clean;
      }
    }
    return array_slice(array_merge($identity, $srcCode, $rest), 0, self::MAX_SOURCES);
  }

  /**
   * Strips a leading OKF frontmatter fence from a body, if present.
   *
   * @param string $body
   *   The raw body (possibly LLM-authored with its own frontmatter).
   *
   * @return string
   *   The body with any leading "---\n…\n---" block removed and trimmed.
   */
  private static function stripFrontmatter(string $body): string {
    if (preg_match('/^---\R(.*?)\R---(?:\R(.*))?$/s', $body, $m) === 1) {
      // Only strip when the fenced block is real YAML frontmatter (a mapping).
      // The lazy (.*?) treats the FIRST later "---" as the closing fence, so a
      // body that merely OPENS with a "---" horizontal rule would otherwise
      // have its heading and first section silently deleted.
      try {
        $decoded = Yaml::decode($m[1]);
      }
      catch (\Throwable) {
        $decoded = NULL;
      }
      if (is_array($decoded) && $decoded !== []) {
        return ltrim($m[2] ?? '');
      }
    }
    return ltrim($body);
  }

  /**
   * The factsheet's primary doc path, when present.
   *
   * @param array<string, mixed> $factsheet
   *   The factsheet packet.
   *
   * @return string|null
   *   The primary doc path, or NULL.
   */
  private function primaryDoc(array $factsheet): ?string {
    $docs = $factsheet['docs'] ?? NULL;
    return is_array($docs) ? $this->scalarString($docs['primary'] ?? NULL) : NULL;
  }

  /**
   * The provenance queries list from the template.
   *
   * @param array<mixed> $template
   *   The provenance_template.
   *
   * @return array<int, string>
   *   The queries, defaulting to the factsheet query id.
   */
  private function queries(array $template): array {
    $out = [];
    $queries = $template['queries'] ?? NULL;
    if (is_array($queries)) {
      foreach ($queries as $q) {
        if (is_string($q) && $q !== '') {
          $out[] = $q;
        }
      }
    }
    return $out === [] ? ['droost_wiki_factsheet'] : $out;
  }

  /**
   * Validates the composed page against the real parse/validate contract.
   *
   * @param string $page
   *   The assembled page text.
   *
   * @throws \Droost\Engine\Wiki\ComposeException
   *   When the page is not a valid managed OKF page.
   */
  private function validate(string $page): void {
    $meta = $this->parser->parse($page);
    if ($meta->isInvalid()) {
      throw new ComposeException('composed page failed validation: ' . (string) $meta->error);
    }
    if (!$meta->isManaged()) {
      throw new ComposeException('composed page is not a managed droost page');
    }
  }

  /**
   * Coerces a mixed value to a non-empty string, or NULL.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string|null
   *   The non-empty string, or NULL.
   */
  private function scalarString(mixed $value): ?string {
    return is_string($value) && trim($value) !== '' ? $value : NULL;
  }

}
