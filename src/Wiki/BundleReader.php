<?php

declare(strict_types=1);

namespace Droost\Engine\Wiki;

use Droost\Engine\Support\PathGuard;
use Droost\Engine\Wiki\Okf\FrontmatterParser;
use Droost\Engine\Wiki\Okf\PageMeta;

/**
 * Lists, reads, and searches the OKF wiki bundle on disk.
 *
 * The bundle-dir analog of ModuleDocReader: every read goes through
 * PathGuard so a symlinked page can never stream content from outside the
 * bundle, and every read is byte-capped so a pathological file can never
 * exhaust memory (reads are bounded at the call site, always).
 */
final readonly class BundleReader {

  /**
   * Default cap on rows the metadata index() builds (one file read each).
   *
   * Bounds the display/list path only. pages() (the enumeration the freshness
   * gate classifies) is uncapped — the gate must see every page.
   */
  private const int MAX_PAGES = 500;

  /**
   * Maximum bytes served from a page body.
   */
  private const int MAX_FILE_BYTES = 16000;

  /**
   * Maximum bytes read when parsing page metadata.
   *
   * Frontmatter lives at the top of the file; anything past this is
   * pathological. Distinct from the display cap so a long (legitimate) body
   * never hides its own frontmatter.
   */
  private const int MAX_META_BYTES = 256000;

  /**
   * OKF-reserved basenames, exempt from staleness.
   */
  public const array RESERVED = ['index.md', 'log.md'];

  /**
   * Constructs a BundleReader.
   *
   * @param \Droost\Engine\Wiki\BundleLocatorInterface $settings
   *   The wiki settings.
   * @param \Droost\Engine\Wiki\Okf\FrontmatterParser $parser
   *   The frontmatter parser.
   */
  public function __construct(
    private BundleLocatorInterface $settings,
    private FrontmatterParser $parser,
  ) {}

  /**
   * Lists the bundle's pages.
   *
   * @return array<int, string>
   *   Every bundle-relative page path (forward slashes), sorted — the full set,
   *   uncapped: the freshness gate must classify all pages, and display callers
   *   bound their own output. Includes reserved files — classification is the
   *   caller's concern.
   */
  public function pages(): array {
    $bundle = $this->settings->bundlePath();
    if (!is_dir($bundle)) {
      return [];
    }
    $found = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($bundle, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'md') {
        continue;
      }
      $found[] = ltrim(str_replace([$bundle, '\\'], ['', '/'], $file->getPathname()), '/');
    }
    sort($found);
    return $found;
  }

  /**
   * The total number of pages in the bundle (uncapped).
   *
   * @return int
   *   The page count, for callers that display a bounded subset but report the
   *   true total.
   */
  public function count(): int {
    return count($this->pages());
  }

  /**
   * Lists pages with their presentational metadata (bounded).
   *
   * Reads one file per row, so it is bounded to $limit rows; list callers show
   * the true total via count() and signal has_more.
   *
   * @param int $limit
   *   Maximum rows to build. Defaults to MAX_PAGES.
   *
   * @return array<int, array{file: string, title: string|null, type: string|null, modules: array<int, string>}>
   *   Up to $limit rows, sorted by file.
   */
  public function index(int $limit = self::MAX_PAGES): array {
    $out = [];
    foreach ($this->pages() as $file) {
      if (count($out) >= $limit) {
        break;
      }
      $meta = $this->readMeta($file);
      $out[] = [
        'file' => $file,
        'title' => $meta?->title,
        'type' => $meta?->type,
        'modules' => $meta?->provenance->modules ?? [],
      ];
    }
    return $out;
  }

  /**
   * Reads one page for display.
   *
   * @param string $file
   *   The bundle-relative page path.
   *
   * @return array{file: string, meta: array{type: string|null, title: string|null, description: string|null, tags: array<int, string>, timestamp: string|null, modules: array<int, string>}, content: string}|null
   *   The page payload (content capped and truncation-marked), or NULL when
   *   the file is missing, escapes the bundle, or is not markdown.
   */
  public function read(string $file): ?array {
    $resolved = $this->resolve($file);
    if ($resolved === NULL) {
      return NULL;
    }
    $raw = $this->readCapped($resolved, self::MAX_FILE_BYTES);
    if ($raw === NULL) {
      return NULL;
    }
    $meta = $this->parser->parse($raw);
    return [
      'file' => $file,
      'meta' => [
        'type' => $meta->type,
        'title' => $meta->title,
        'description' => $meta->description,
        'tags' => $meta->tags,
        'timestamp' => $meta->timestamp,
        'modules' => $meta->provenance->modules ?? [],
      ],
      'content' => $raw,
    ];
  }

  /**
   * Parses one page's metadata (uncapped-frontmatter read).
   *
   * @param string $file
   *   The bundle-relative page path.
   *
   * @return \Droost\Engine\Wiki\Okf\PageMeta|null
   *   The parse verdict, or NULL when the file is missing, escapes the
   *   bundle, or cannot be read — callers treat NULL as fail-closed.
   */
  public function readMeta(string $file): ?PageMeta {
    $resolved = $this->resolve($file);
    if ($resolved === NULL) {
      return NULL;
    }
    $raw = $this->readCapped($resolved, self::MAX_META_BYTES);
    return $raw === NULL ? NULL : $this->parser->parse($raw);
  }

  /**
   * Searches page content for a query string.
   *
   * @param string $query
   *   The case-insensitive search term.
   * @param int $maxResults
   *   Maximum result rows.
   * @param int $perFile
   *   Maximum matching lines per page.
   *
   * @return array<int, array{file: string, matches: array<int, string>}>
   *   Pages with matching lines (240-char snippets).
   */
  public function search(string $query, int $maxResults, int $perFile): array {
    $needle = mb_strtolower($query);
    $results = [];
    foreach ($this->pages() as $file) {
      if (count($results) >= $maxResults) {
        break;
      }
      $resolved = $this->resolve($file);
      if ($resolved === NULL) {
        continue;
      }
      $raw = $this->readCapped($resolved, self::MAX_FILE_BYTES);
      if ($raw === NULL) {
        continue;
      }
      $matches = [];
      foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        if (count($matches) >= $perFile) {
          break;
        }
        $line = trim($line);
        if ($line !== '' && str_contains(mb_strtolower($line), $needle)) {
          $matches[] = mb_substr($line, 0, 240);
        }
      }
      if ($matches !== []) {
        $results[] = ['file' => $file, 'matches' => $matches];
      }
    }
    return $results;
  }

  /**
   * Whether a page path is an OKF-reserved file.
   *
   * @param string $file
   *   The bundle-relative page path.
   *
   * @return bool
   *   TRUE for index.md / log.md at any depth.
   */
  public static function isReserved(string $file): bool {
    return in_array(strtolower(basename($file)), self::RESERVED, TRUE);
  }

  /**
   * Sanitizes and resolves a page path inside the bundle.
   *
   * @param string $file
   *   The requested bundle-relative path.
   *
   * @return string|null
   *   The resolved absolute path, or NULL when unsafe or missing.
   */
  private function resolve(string $file): ?string {
    $file = ltrim(str_replace('\\', '/', $file), '/');
    if ($file === '' || str_contains($file, '..') || !str_ends_with(strtolower($file), '.md')) {
      return NULL;
    }
    return PathGuard::resolve($this->settings->bundlePath(), $file);
  }

  /**
   * Reads a file with a byte cap and truncation marker.
   *
   * @param string $path
   *   The absolute file path.
   * @param positive-int $cap
   *   The maximum bytes to return.
   *
   * @return string|null
   *   The (possibly truncated) content, or NULL when unreadable.
   */
  private function readCapped(string $path, int $cap): ?string {
    $content = @file_get_contents($path, FALSE, NULL, 0, $cap + 1);
    if ($content === FALSE) {
      return NULL;
    }
    if (strlen($content) > $cap) {
      // Mb_strcut (not substr) so the cut never splits a multibyte character
      // into invalid bytes that would corrupt the JSON response.
      $content = mb_strcut($content, 0, $cap) . "\n…(truncated)";
    }
    return $content;
  }

}
