<?php

declare(strict_types=1);

namespace Droost\Engine\Search;

/**
 * The result of diffing the file manifest against a discovery pass.
 *
 * Paths are relative to the app root, exactly as SourceDiscovery emits them
 * and the manifest stores them.
 */
final readonly class IndexDelta {

  /**
   * Constructs an IndexDelta.
   *
   * @param array<int, string> $added
   *   Discovered paths with no manifest entry.
   * @param array<int, string> $changed
   *   Discovered paths whose content hash differs from the manifest.
   * @param array<int, string> $removed
   *   Manifest paths (within the requested scopes) no longer discovered.
   * @param int $unchanged
   *   Discovered paths whose hash matches the manifest.
   */
  public function __construct(
    public array $added,
    public array $changed,
    public array $removed,
    public int $unchanged,
  ) {}

  /**
   * The paths that need (re-)parsing: added + changed.
   *
   * @return array<int, string>
   *   The paths to parse.
   */
  public function toParse(): array {
    return array_merge($this->added, $this->changed);
  }

  /**
   * The paths whose derived rows must be dropped: changed + removed.
   *
   * @return array<int, string>
   *   The paths to drop.
   */
  public function toDrop(): array {
    return array_merge($this->changed, $this->removed);
  }

  /**
   * Whether nothing changed at all.
   *
   * @return bool
   *   TRUE when there is no work.
   */
  public function isEmpty(): bool {
    return $this->added === [] && $this->changed === [] && $this->removed === [];
  }

}
