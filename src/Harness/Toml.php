<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

/**
 * Minimal TOML emitter for the Codex MCP-server table.
 *
 * Codex stores MCP servers as `[mcp_servers.<name>]` tables. Rather than depend
 * on a TOML library and round-trip the whole file, Droost manages only its own
 * table inside a comment-fenced region (see Markers::TOML_*), emitted by this
 * helper. Inputs are simple shell tokens, so a tiny serializer is sufficient.
 */
final class Toml {

  /**
   * Quotes and escapes a string as a TOML basic string.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   The quoted, escaped TOML string.
   */
  public static function string(string $value): string {
    $escaped = str_replace(
      ['\\', '"', "\n", "\r", "\t"],
      ['\\\\', '\\"', '\\n', '\\r', '\\t'],
      $value,
    );
    return '"' . $escaped . '"';
  }

  /**
   * Emits a TOML array of strings (e.g. command args).
   *
   * @param array<int, string> $values
   *   The string values.
   *
   * @return string
   *   The TOML inline array.
   */
  public static function stringArray(array $values): string {
    return '[' . implode(', ', array_map(self::string(...), array_values($values))) . ']';
  }

  /**
   * Builds the Codex `[mcp_servers.<name>]` table body.
   *
   * @param string $name
   *   The server name.
   * @param string $command
   *   The launch command.
   * @param array<int, string> $args
   *   The command arguments.
   *
   * @return string
   *   The TOML table (no surrounding markers).
   */
  public static function mcpServerTable(string $name, string $command, array $args): string {
    return '[mcp_servers.' . $name . "]\n"
      . 'command = ' . self::string($command) . "\n"
      . 'args = ' . self::stringArray($args) . "\n";
  }

}
