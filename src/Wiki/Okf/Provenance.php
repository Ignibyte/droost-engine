<?php

declare(strict_types=1);

namespace Droost\Engine\Wiki\Okf;

/**
 * The droost provenance extension of an OKF wiki page.
 *
 * The staleness contract (docs/provenance-spec.md): which source files a page
 * covers and their content hashes at generation time. Instances are only ever
 * constructed from validated data — fromArray() rejects malformed input with
 * a precise reason, which the status checker surfaces as an "invalid" page
 * verdict (fail-closed) rather than an exception.
 */
final readonly class Provenance {

  /**
   * Contract versions this implementation understands.
   */
  private const array SUPPORTED_SPECS = [1];

  /**
   * Constructs a Provenance.
   *
   * @param int $spec
   *   The contract version.
   * @param array<int, string> $modules
   *   Covered module machine names (non-empty).
   * @param array<int, array{path: string, hash: string}> $sources
   *   Covered files: project-root-relative path + algo-prefixed content hash.
   * @param string|null $generatedCommit
   *   The git HEAD at generation time (metadata only, never compared).
   * @param string|null $generator
   *   Free-form generating-agent identity.
   * @param array<int, string> $queries
   *   Droost tools consulted during generation.
   */
  public function __construct(
    public int $spec,
    public array $modules,
    public array $sources,
    public ?string $generatedCommit,
    public ?string $generator,
    public array $queries,
  ) {}

  /**
   * Builds a Provenance from decoded frontmatter data.
   *
   * @param mixed $data
   *   The value of the frontmatter "droost" key.
   *
   * @return self
   *   The validated provenance.
   *
   * @throws \InvalidArgumentException
   *   With a precise reason when the data violates the contract. Callers
   *   convert this into an "invalid" page verdict.
   */
  public static function fromArray(mixed $data): self {
    if (!is_array($data)) {
      throw new \InvalidArgumentException('the droost block is not a mapping');
    }
    $spec = $data['spec'] ?? NULL;
    if (!is_int($spec)) {
      throw new \InvalidArgumentException('droost.spec is missing or not an integer');
    }
    if (!in_array($spec, self::SUPPORTED_SPECS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('droost.spec %d is not a supported contract version', $spec));
    }
    return new self(
      $spec,
      self::stringList($data['modules'] ?? NULL, 'droost.modules'),
      self::sources($data['sources'] ?? NULL),
      self::optionalString($data['generated_commit'] ?? NULL, 'droost.generated_commit'),
      self::optionalString($data['generator'] ?? NULL, 'droost.generator'),
      isset($data['queries']) ? self::stringList($data['queries'], 'droost.queries') : [],
    );
  }

  /**
   * Validates a required non-empty list of non-empty strings.
   *
   * @param mixed $value
   *   The raw value.
   * @param string $key
   *   The key name, for the error message.
   *
   * @return array<int, string>
   *   The validated list.
   *
   * @throws \InvalidArgumentException
   *   When the value is not a non-empty list of non-empty strings.
   */
  private static function stringList(mixed $value, string $key): array {
    if (!is_array($value) || $value === [] || !array_is_list($value)) {
      throw new \InvalidArgumentException(sprintf('%s is missing or not a non-empty list', $key));
    }
    $out = [];
    foreach ($value as $item) {
      if (!is_string($item) || trim($item) === '') {
        throw new \InvalidArgumentException(sprintf('%s contains a non-string or empty entry', $key));
      }
      $out[] = $item;
    }
    return $out;
  }

  /**
   * Validates the sources list.
   *
   * @param mixed $value
   *   The raw droost.sources value.
   *
   * @return array<int, array{path: string, hash: string}>
   *   The validated sources.
   *
   * @throws \InvalidArgumentException
   *   When an entry violates the contract.
   */
  private static function sources(mixed $value): array {
    if (!is_array($value) || $value === [] || !array_is_list($value)) {
      throw new \InvalidArgumentException('droost.sources is missing or not a non-empty list');
    }
    $out = [];
    foreach ($value as $i => $entry) {
      if (!is_array($entry)) {
        throw new \InvalidArgumentException(sprintf('droost.sources[%d] is not a mapping', $i));
      }
      $path = $entry['path'] ?? NULL;
      $hash = $entry['hash'] ?? NULL;
      if (!is_string($path) || trim($path) === '') {
        throw new \InvalidArgumentException(sprintf('droost.sources[%d].path is missing or empty', $i));
      }
      if (str_starts_with($path, '/') || str_contains($path, '\\') || in_array('..', explode('/', $path), TRUE)) {
        throw new \InvalidArgumentException(sprintf('droost.sources[%d].path must be project-root-relative with forward slashes and no ".." segments', $i));
      }
      if (!is_string($hash) || preg_match('/^[a-z0-9]+:[0-9a-f]+$/', $hash) !== 1) {
        throw new \InvalidArgumentException(sprintf('droost.sources[%d].hash is not an algo-prefixed lowercase hex hash', $i));
      }
      $out[] = ['path' => $path, 'hash' => $hash];
    }
    return $out;
  }

  /**
   * Validates an optional string value.
   *
   * @param mixed $value
   *   The raw value.
   * @param string $key
   *   The key name, for the error message.
   *
   * @return string|null
   *   The string, or NULL when absent.
   *
   * @throws \InvalidArgumentException
   *   When present but not a string.
   */
  private static function optionalString(mixed $value, string $key): ?string {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_string($value)) {
      throw new \InvalidArgumentException(sprintf('%s is not a string', $key));
    }
    return $value;
  }

}
