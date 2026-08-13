<?php

declare(strict_types=1);

namespace Droost\Engine\Support;

/**
 * Reads the current git commit sha without spawning a VCS binary.
 *
 * A pure parse of `.git/HEAD` (plus one ref indirection and a packed-refs
 * fallback) under the project root. Freshness signals — the wiki's
 * generated_commit, the search index's HEAD stamp — bind to this cheap read;
 * a non-git deploy simply yields '' and the callers degrade to their
 * pull-based behavior. Shared by the freshness spine (TICKET-103) and the
 * wiki generator (TICKET-102); created by whichever ships first.
 */
final readonly class GitHead {

  /**
   * Constructs a GitHead.
   *
   * @param \Droost\Engine\Support\ProjectRoot $projectRoot
   *   The project-root resolver (the directory where .git lives).
   */
  public function __construct(
    private ProjectRoot $projectRoot,
  ) {}

  /**
   * The current commit sha, or '' when unreadable / not a git checkout.
   *
   * @return string
   *   The commit sha, or ''.
   */
  public function current(): string {
    $root = $this->projectRoot->path();
    $head = @file_get_contents($root . '/.git/HEAD');
    if (!is_string($head)) {
      return '';
    }
    $parsed = self::parseHead($head);
    if ($parsed['sha'] !== '') {
      return $parsed['sha'];
    }
    if ($parsed['ref'] === '') {
      return '';
    }
    // A symbolic ref: read the loose ref file, then fall back to packed-refs.
    $loose = @file_get_contents($root . '/.git/' . $parsed['ref']);
    if (is_string($loose) && ($sha = self::firstSha($loose)) !== '') {
      return $sha;
    }
    return self::packedRef($root, $parsed['ref']);
  }

  /**
   * Parses a .git/HEAD payload into a direct sha or a symbolic ref.
   *
   * @param string $content
   *   The raw HEAD file content.
   *
   * @return array{sha: string, ref: string}
   *   At most one field is non-empty: sha for a detached HEAD, ref for a
   *   symbolic HEAD; both '' when the content is neither.
   */
  public static function parseHead(string $content): array {
    $line = trim($content);
    if (str_starts_with($line, 'ref:')) {
      return ['sha' => '', 'ref' => trim(substr($line, 4))];
    }
    return ['sha' => self::firstSha($line), 'ref' => ''];
  }

  /**
   * Returns the first hex sha token (40-64 chars) in a string, or ''.
   *
   * @param string $text
   *   The candidate text (a HEAD payload, a loose ref, or a packed column).
   *
   * @return string
   *   The sha, or ''.
   */
  private static function firstSha(string $text): string {
    $tokens = preg_split('/\s+/', trim($text)) ?: [];
    foreach ($tokens as $token) {
      if (preg_match('/^[0-9a-f]{40,64}$/', $token) === 1) {
        return $token;
      }
    }
    return '';
  }

  /**
   * Resolves a ref from .git/packed-refs.
   *
   * @param string $root
   *   The project root.
   * @param string $ref
   *   The full ref path, e.g. "refs/heads/main".
   *
   * @return string
   *   The sha, or ''.
   */
  private static function packedRef(string $root, string $ref): string {
    $packed = @file_get_contents($root . '/.git/packed-refs');
    if (!is_string($packed)) {
      return '';
    }
    foreach (explode("\n", $packed) as $line) {
      $line = trim($line);
      // Skip comments and peeled-tag annotations ("^<sha>").
      if ($line === '' || $line[0] === '#' || $line[0] === '^') {
        continue;
      }
      $parts = preg_split('/\s+/', $line, 2);
      if (is_array($parts) && count($parts) === 2 && $parts[1] === $ref) {
        return self::firstSha($parts[0]);
      }
    }
    return '';
  }

}
