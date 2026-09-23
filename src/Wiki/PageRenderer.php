<?php

declare(strict_types=1);

namespace Droost\Engine\Wiki;

/**
 * Writes a wiki page body from a factsheet alone, with no model.
 *
 * A page an agent or a model writes says what the code is for. This one says
 * only what droost measured: identity, what the extension ships, where it
 * sits in the code graph, what it lets other code extend, and how this
 * project's own code uses it. That is enough for a contrib module nobody here
 * wrote, and it costs nothing to keep a hundred of them fresh.
 *
 * Deterministic by construction. The same factsheet renders the same bytes,
 * with no timestamp or run-dependent wording in the body, so regenerating an
 * unchanged extension's page changes nothing on disk. A section the factsheet
 * could not fill says so, in the factsheet's own reason, because an empty
 * section reads as "nothing here" when the truth is "nobody measured".
 */
final readonly class PageRenderer {

  /**
   * The generator identity a rendered page records.
   */
  public const string GENERATOR = 'droost:wiki:render';

  /**
   * Rows shown per table, the rest counted.
   */
  public const int MAX_ROWS = 40;

  /**
   * Renders the body for one extension.
   *
   * @param string $name
   *   The extension machine name.
   * @param array<string, mixed> $factsheet
   *   The factsheet packet for $name.
   *
   * @return string
   *   Markdown, ending in one newline.
   */
  public function render(string $name, array $factsheet): string {
    $identity = self::map($factsheet['identity'] ?? NULL);
    $kind = self::text($identity['kind'] ?? NULL) === 'theme' ? 'theme' : 'module';
    $label = self::text($identity['label'] ?? NULL);
    $out = [];
    $out[] = '# ' . ($label !== '' && $label !== $name ? sprintf('%s (`%s`)', $label, $name) : sprintf('`%s`', $name));
    $out[] = '';
    $description = self::text($identity['description'] ?? NULL);
    if ($description !== '') {
      $out[] = $description;
      $out[] = '';
    }
    $out[] = sprintf('This page was rendered from droost\'s factsheet for the %s, with no model. It states what droost measured and nothing else.', $kind);
    $out[] = '';
    $out = [...$out, ...self::identityTable($name, $kind, $identity)];
    $out = [...$out, ...self::themeSection(self::map($factsheet['theme'] ?? NULL))];
    $out = [...$out, ...self::providesSection($factsheet)];
    $out = [...$out, ...self::extensionPointsSection($factsheet['extension_points'] ?? NULL)];
    $out = [...$out, ...self::usageSection($factsheet['usage'] ?? NULL)];
    $out = [...$out, ...self::graphSection($factsheet['graph'] ?? NULL)];
    $out = [...$out, ...self::docsSection(self::map($factsheet['docs'] ?? NULL))];
    return rtrim(implode("\n", $out)) . "\n";
  }

  /**
   * The identity table.
   *
   * @param string $name
   *   The machine name.
   * @param string $kind
   *   Either 'module' or 'theme'.
   * @param array<mixed> $identity
   *   The factsheet's identity section.
   *
   * @return list<string>
   *   Lines.
   */
  private static function identityTable(string $name, string $kind, array $identity): array {
    $rows = [
      ['Machine name', '`' . $name . '`'],
      ['Kind', $kind],
    ];
    foreach (['provenance' => 'Owner', 'package' => 'Package', 'version' => 'Version'] as $key => $label) {
      $value = self::text($identity[$key] ?? NULL);
      if ($value !== '') {
        $rows[] = [$label, $value];
      }
    }
    $path = self::text($identity['project_path'] ?? $identity['path'] ?? NULL);
    if ($path !== '') {
      $rows[] = ['Path', '`' . $path . '`'];
    }
    $dependencies = self::strings($identity['dependencies'] ?? NULL);
    if ($dependencies !== []) {
      $rows[] = ['Depends on', implode(', ', array_map(static fn (string $d): string => '`' . $d . '`', $dependencies))];
    }
    return [...self::table(['', ''], $rows), ''];
  }

  /**
   * A theme's own facts: base theme, regions, libraries, components.
   *
   * @param array<mixed> $theme
   *   The factsheet's theme section, empty for a module.
   *
   * @return list<string>
   *   Lines, none for a module.
   */
  private static function themeSection(array $theme): array {
    if ($theme === []) {
      return [];
    }
    $out = ['## The theme', ''];
    $base = self::text($theme['base_theme'] ?? NULL);
    $out[] = $base !== '' ? sprintf('Base theme: `%s`.', $base) : 'No base theme.';
    $out[] = '';
    $regions = self::map($theme['regions'] ?? NULL);
    if ($regions !== []) {
      $rows = [];
      foreach ($regions as $id => $label) {
        $rows[] = ['`' . $id . '`', self::cell(self::text($label))];
      }
      $out = [...$out, '### Regions', '', ...self::table(['Region', 'Label'], $rows), ''];
    }
    foreach (['libraries' => 'Libraries', 'components' => 'Components'] as $key => $heading) {
      $names = self::strings($theme[$key] ?? NULL);
      if ($names !== []) {
        $out = [...$out, sprintf('### %s (%d)', $heading, count($names)), '', ...self::bullets($names), ''];
      }
    }
    $templates = $theme['templates'] ?? NULL;
    if (is_int($templates)) {
      $out[] = sprintf('%d Twig template(s) override or add to what the base theme renders.', $templates);
      $out[] = '';
    }
    return $out;
  }

  /**
   * What the extension ships: services, routes, and harvested patterns.
   *
   * @param array<string, mixed> $factsheet
   *   The factsheet.
   *
   * @return list<string>
   *   Lines.
   */
  private static function providesSection(array $factsheet): array {
    $out = ['## What it provides', ''];
    $sections = [
      'services' => ['Services', ['Service', 'Class'], ['id', 'class']],
      'routes' => ['Routes', ['Route', 'Path', 'Handler'], ['name', 'path', 'handler']],
      'patterns' => ['Plugins and other patterns', ['Kind', 'Name', 'Class'], ['kind', 'name', 'fqcn']],
    ];
    $any = FALSE;
    foreach ($sections as $key => [$heading, $columns, $fields]) {
      $section = self::map($factsheet[$key] ?? NULL);
      if ($section === []) {
        continue;
      }
      if (($section['available'] ?? FALSE) !== TRUE) {
        array_push($out, '### ' . $heading, '', 'Not listed: ' . self::reason($section['reason'] ?? NULL, 'not measured') . '.', '');
        $any = TRUE;
        continue;
      }
      $items = self::rows($section['items'] ?? NULL);
      if ($items === []) {
        continue;
      }
      $any = TRUE;
      $total = is_int($section['total'] ?? NULL) ? $section['total'] : count($items);
      $rows = [];
      foreach (array_slice($items, 0, self::MAX_ROWS) as $item) {
        $rows[] = array_map(static fn (string $field): string => self::code(self::text($item[$field] ?? NULL)), $fields);
      }
      $out = [...$out, sprintf('### %s (%d)', $heading, $total), '', ...self::table($columns, $rows)];
      if ($total > count($rows)) {
        $out[] = '';
        $out[] = sprintf('%d more not shown.', $total - count($rows));
      }
      $out[] = '';
    }
    if (!$any) {
      $out[] = 'No services, routes or harvested patterns.';
      $out[] = '';
    }
    return $out;
  }

  /**
   * What the extension lets other code extend.
   *
   * @param mixed $section
   *   The factsheet's extension_points section.
   *
   * @return list<string>
   *   Lines, none when the factsheet has no such section.
   */
  private static function extensionPointsSection(mixed $section): array {
    $section = self::map($section);
    if ($section === []) {
      return [];
    }
    $out = ['## What it lets other code extend', ''];
    if (($section['available'] ?? TRUE) !== TRUE) {
      return [...$out, 'Not listed: ' . self::reason($section['reason'] ?? NULL, 'not measured') . '.', ''];
    }
    $plugins = self::rows($section['plugin_types'] ?? NULL);
    if ($plugins !== []) {
      $rows = [];
      foreach (array_slice($plugins, 0, self::MAX_ROWS) as $row) {
        $rows[] = [self::code(self::text($row['type'] ?? NULL)), self::code(self::text($row['example'] ?? NULL))];
      }
      $table = self::table(['Plugin type', 'An implementation to copy'], $rows);
      $out = [...$out, sprintf('### Plugin types (%d)', count($plugins)), '', ...$table, ''];
    }
    $hooks = self::strings($section['hooks'] ?? NULL);
    if ($hooks !== []) {
      $shown = self::bullets(self::codes(array_slice($hooks, 0, self::MAX_ROWS)));
      $out = [...$out, sprintf('### Hooks it invokes (%d)', count($hooks)), '', ...$shown, ''];
    }
    if ($plugins === [] && $hooks === []) {
      array_push($out, 'None found: no plugin type and no documented hook.', '');
    }
    return $out;
  }

  /**
   * How this project's own code uses the extension.
   *
   * @param mixed $section
   *   The factsheet's usage section.
   *
   * @return list<string>
   *   Lines, none when the factsheet has no such section.
   */
  private static function usageSection(mixed $section): array {
    $section = self::map($section);
    if ($section === []) {
      return [];
    }
    $out = ['## How this project uses it', ''];
    if (($section['available'] ?? TRUE) !== TRUE) {
      return [...$out, self::unmeasured($section['reason'] ?? NULL), ''];
    }
    $rows = [];
    foreach (self::rows($section['by'] ?? NULL) as $row) {
      $rows[] = [self::code(self::text($row['module'] ?? NULL)), self::count($row['edges'] ?? NULL)];
    }
    if ($rows === []) {
      return [...$out, 'No custom code here reaches it.', ''];
    }
    return [...$out, ...self::table(['Custom extension', 'References'], $rows), ''];
  }

  /**
   * Where the extension sits in the code graph.
   *
   * @param mixed $section
   *   The factsheet's graph section.
   *
   * @return list<string>
   *   Lines.
   */
  private static function graphSection(mixed $section): array {
    $section = self::map($section);
    $out = ['## Where it sits', ''];
    if ($section === [] || ($section['available'] ?? FALSE) !== TRUE) {
      return [...$out, self::unmeasured($section['reason'] ?? NULL), ''];
    }
    foreach (['depends_on' => 'It reaches into', 'used_by' => 'Reached from'] as $key => $heading) {
      $rows = [];
      foreach (array_slice(self::rows($section[$key] ?? NULL), 0, self::MAX_ROWS) as $row) {
        $rows[] = [
          self::code(self::text($row['module'] ?? NULL)),
          self::count($row['edges'] ?? NULL),
          self::count($row['symbols'] ?? NULL),
        ];
      }
      $out[] = '### ' . $heading;
      $out[] = '';
      if ($rows === []) {
        $out[] = 'Nothing.';
      }
      else {
        $out = [...$out, ...self::table(['Extension', 'References', 'Symbols'], $rows)];
      }
      $out[] = '';
    }
    return $out;
  }

  /**
   * The extension's own documentation files.
   *
   * @param array<mixed> $docs
   *   The factsheet's docs section.
   *
   * @return list<string>
   *   Lines, none when it ships no docs.
   */
  private static function docsSection(array $docs): array {
    $files = self::strings($docs['files'] ?? NULL);
    if ($files === []) {
      return [];
    }
    return ['## Its own documentation', '', ...self::bullets(self::codes($files)), ''];
  }

  /**
   * The line for a section nobody could measure.
   *
   * @param mixed $reason
   *   The factsheet's reason.
   *
   * @return string
   *   The line.
   */
  private static function unmeasured(mixed $reason): string {
    return 'Not measured: ' . self::reason($reason, 'the code graph is not built') . '.';
  }

  /**
   * A factsheet reason, safe in a cell, or a fallback.
   *
   * @param mixed $reason
   *   The factsheet's reason.
   * @param string $fallback
   *   What to say when it gave none.
   *
   * @return string
   *   The reason.
   */
  private static function reason(mixed $reason, string $fallback): string {
    $text = self::cell(self::text($reason));
    return $text === '' ? $fallback : $text;
  }

  /**
   * A count as a cell, zero when absent.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   The cell.
   */
  private static function count(mixed $value): string {
    return (string) (is_int($value) ? $value : 0);
  }

  /**
   * Names as inline code.
   *
   * @param list<string> $names
   *   The names.
   *
   * @return list<string>
   *   The names, each in backticks.
   */
  private static function codes(array $names): array {
    return array_map(static fn (string $name): string => '`' . $name . '`', $names);
  }

  /**
   * A Markdown table.
   *
   * @param list<string> $header
   *   Column headings.
   * @param list<list<string>> $rows
   *   Cells, already escaped.
   *
   * @return list<string>
   *   Lines.
   */
  private static function table(array $header, array $rows): array {
    $line = self::row(...);
    return [$line($header), $line(array_fill(0, count($header), '---')), ...array_map($line, $rows)];
  }

  /**
   * One Markdown table row.
   *
   * @param list<string> $cells
   *   Cells, already escaped.
   *
   * @return string
   *   The row.
   */
  private static function row(array $cells): string {
    return '| ' . implode(' | ', $cells) . ' |';
  }

  /**
   * A bullet list.
   *
   * @param list<string> $items
   *   Items, already escaped.
   *
   * @return list<string>
   *   Lines.
   */
  private static function bullets(array $items): array {
    return array_map(static fn (string $item): string => '- ' . $item, $items);
  }

  /**
   * A value as inline code, or a dash when empty.
   *
   * GFM splits a table row on every unescaped pipe, inside a code span too,
   * so the pipe is escaped here as in any other cell.
   *
   * @param string $value
   *   The value.
   *
   * @return string
   *   The cell.
   */
  private static function code(string $value): string {
    return $value === '' ? '—' : '`' . str_replace(['`', '|'], ["'", '\\|'], $value) . '`';
  }

  /**
   * A value safe inside a table cell.
   *
   * @param string $value
   *   The value.
   *
   * @return string
   *   The value with pipes escaped and newlines flattened.
   */
  private static function cell(string $value): string {
    return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $value);
  }

  /**
   * A value as an array, or empty.
   *
   * @param mixed $value
   *   The value.
   *
   * @return array<mixed>
   *   The array.
   */
  private static function map(mixed $value): array {
    return is_array($value) ? $value : [];
  }

  /**
   * The array rows of a list, dropping anything else.
   *
   * @param mixed $value
   *   The value.
   *
   * @return list<array<mixed>>
   *   The rows.
   */
  private static function rows(mixed $value): array {
    return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
  }

  /**
   * The non-empty strings of a list, in order.
   *
   * @param mixed $value
   *   The value.
   *
   * @return list<string>
   *   The strings.
   */
  private static function strings(mixed $value): array {
    $out = [];
    foreach (is_array($value) ? $value : [] as $item) {
      if (is_string($item) && trim($item) !== '') {
        $out[] = trim($item);
      }
    }
    return $out;
  }

  /**
   * A scalar as a trimmed single-line string.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   The string, or ''.
   */
  private static function text(mixed $value): string {
    return is_scalar($value) ? trim(preg_replace('/\s+/', ' ', (string) $value) ?? '') : '';
  }

}
