<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Reads, mutates, and writes a JSON config file preserving sibling content.
 *
 * Used for harness configs that are JSON (.mcp.json, opencode.json,
 * .qwen/.gemini settings.json). A malformed or missing file is treated as an
 * empty object so a write never throws.
 */
final class JsonMerge {

  /**
   * Decodes a JSON file to an associative array (empty on missing/malformed).
   *
   * @param string $path
   *   The file path.
   *
   * @return array<string, mixed>
   *   The decoded data, or [] if absent or unparseable.
   */
  public static function read(string $path): array {
    if (!is_file($path)) {
      return [];
    }
    // Objects are decoded as objects, then turned into arrays where that is
    // safe: an empty object, or one whose keys read as a list, stays an
    // object, so encode() writes it back as `{}`. Decoding straight to arrays
    // turned another server's `"env": {}` into `"env": []`, which its client
    // may reject.
    $decoded = self::arrays(json_decode((string) file_get_contents($path), FALSE));
    if (!is_array($decoded)) {
      return [];
    }
    // A JSON config file's top level is an object → string keys.
    /** @var array<string, mixed> $decoded */
    return $decoded;
  }

  /**
   * A decoded value with objects as arrays where JSON keeps its shape.
   *
   * @param mixed $value
   *   The value json_decode() gave with objects as stdClass.
   *
   * @return mixed
   *   Arrays for objects with keys that cannot read as a list, the empty or
   *   list-keyed object kept as an object, everything else unchanged.
   */
  private static function arrays(mixed $value): mixed {
    if (is_array($value)) {
      return array_map(self::arrays(...), $value);
    }
    if (!$value instanceof \stdClass) {
      return $value;
    }
    $properties = get_object_vars($value);
    $converted = [];
    foreach ($properties as $key => $child) {
      $converted[$key] = self::arrays($child);
    }
    if ($converted === [] || array_is_list($converted)) {
      $object = new \stdClass();
      foreach ($converted as $key => $child) {
        $object->{$key} = $child;
      }
      return $object;
    }
    return $converted;
  }

  /**
   * Whether a file exists with non-empty content that is NOT valid JSON.
   *
   * Both an absent file and an unparseable one decode to [] via read(), so a
   * caller about to rewrite the file must distinguish them: merging onto [] and
   * writing would silently destroy a present-but-malformed config and every
   * server/secret it holds.
   *
   * @param string $path
   *   The file path.
   *
   * @return bool
   *   TRUE when the file is present and non-empty but does not decode to an
   *   array; FALSE when absent, empty, or valid JSON.
   */
  public static function isMalformed(string $path): bool {
    if (!is_file($path)) {
      return FALSE;
    }
    $raw = trim((string) file_get_contents($path));
    if ($raw === '') {
      return FALSE;
    }
    return !is_array(json_decode($raw, TRUE));
  }

  /**
   * Encodes data to the JSON form used for harness configs.
   *
   * @param array<string, mixed> $data
   *   The data to encode.
   *
   * @return string
   *   Pretty-printed JSON with unescaped slashes and a trailing newline.
   */
  public static function encode(array $data): string {
    return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
  }

  /**
   * Sets a value at a two-level key path, creating the parent map if needed.
   *
   * @param array<string, mixed> $data
   *   The data to mutate.
   * @param string $parent
   *   The parent key (e.g. "mcpServers").
   * @param string $key
   *   The child key (e.g. "droost").
   * @param mixed $value
   *   The value to set.
   *
   * @return array<string, mixed>
   *   The mutated data.
   */
  public static function setPath(array $data, string $parent, string $key, mixed $value): array {
    if (!isset($data[$parent]) || !is_array($data[$parent])) {
      $data[$parent] = [];
    }
    $data[$parent][$key] = $value;
    return $data;
  }

  /**
   * Merges keys into the value at a two-level key path, keeping the others.
   *
   * A re-install sets droost's own keys (its command) and keeps whatever the
   * user added to the same entry (`env`, `cwd`, a timeout).
   *
   * @param array<string, mixed> $data
   *   The data to mutate.
   * @param string $parent
   *   The parent key (e.g. "mcpServers").
   * @param string $key
   *   The child key (e.g. "droost").
   * @param array<string, mixed> $values
   *   The keys to set.
   *
   * @return array<string, mixed>
   *   The mutated data.
   */
  public static function mergePath(array $data, string $parent, string $key, array $values): array {
    $current = is_array($data[$parent] ?? NULL) && is_array($data[$parent][$key] ?? NULL) ? $data[$parent][$key] : [];
    return self::setPath($data, $parent, $key, array_replace($current, $values));
  }

  /**
   * Removes a value at a two-level key path; drops the parent map if emptied.
   *
   * @param array<string, mixed> $data
   *   The data to mutate.
   * @param string $parent
   *   The parent key.
   * @param string $key
   *   The child key.
   *
   * @return array<string, mixed>
   *   The mutated data.
   */
  public static function unsetPath(array $data, string $parent, string $key): array {
    if (isset($data[$parent]) && is_array($data[$parent])) {
      unset($data[$parent][$key]);
      if ($data[$parent] === []) {
        unset($data[$parent]);
      }
    }
    return $data;
  }

}
