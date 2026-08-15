<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Graph;

use Droost\Engine\Support\Yaml;

/**
 * Extracts graph rows from `*.services.yml` and `*.routing.yml` files.
 *
 * The framework wiring that PHP parsing cannot see: a services file yields a
 * `service:<id>` pseudo-symbol per service plus `service_class` (service →
 * class FQCN) and `injects` (service → `service:<argument-id>`) edges; a
 * routing file yields a `route:<name>` pseudo-symbol per route plus a
 * `routes_to` edge to the `_controller` callable (normalized `Class::method`;
 * bare invokable classes get `::__invoke`) or the `_form` class. Routes using
 * entity-handler defaults (`_entity_form` etc.) keep their pseudo-symbol but
 * emit no edge — resolving handler maps is type-inference territory. Line
 * numbers are best-effort text positions (the YAML parser exposes none).
 */
final class YamlGraphExtractor {

  /**
   * Extracts symbols and edges from one yaml file.
   *
   * @param string $content
   *   The file content.
   * @param string $file
   *   The app-root-relative path.
   * @param string $module
   *   The owning extension.
   *
   * @return array{symbols: array<int, array{fqcn: string, kind: string, file: string, line: int, module: string}>, edges: array<int, array{src: string, dst: string, kind: string}>}
   *   The collected rows (empty for malformed or unrecognized files).
   */
  public function extract(string $content, string $file, string $module): array {
    if (str_ends_with($file, '.services.yml')) {
      return $this->fromServices($content, $file, $module);
    }
    if (str_ends_with($file, '.routing.yml')) {
      return $this->fromRouting($content, $file, $module);
    }
    return ['symbols' => [], 'edges' => []];
  }

  /**
   * Extracts service pseudo-symbols + service_class/injects edges.
   *
   * @param string $content
   *   The file content.
   * @param string $file
   *   The file path.
   * @param string $module
   *   The owning extension.
   *
   * @return array{symbols: array<int, array{fqcn: string, kind: string, file: string, line: int, module: string}>, edges: array<int, array{src: string, dst: string, kind: string}>}
   *   The rows.
   */
  private function fromServices(string $content, string $file, string $module): array {
    $data = self::decode($content);
    $services = is_array($data['services'] ?? NULL) ? $data['services'] : [];
    $symbols = [];
    $edges = [];
    foreach ($services as $id => $definition) {
      // Skip Symfony's underscore directives (_defaults, _instanceof): they are
      // not services, so they must not become service: pseudo-symbols.
      if (!is_string($id) || str_starts_with($id, '_')) {
        continue;
      }
      $node = 'service:' . $id;
      $symbols[] = [
        'fqcn' => $node,
        'kind' => 'service',
        'file' => $file,
        'line' => self::lineOf($content, $id),
        'module' => $module,
      ];
      $class = is_array($definition) && is_string($definition['class'] ?? NULL) ? $definition['class'] : '';
      if ($class === '' && str_contains($id, '\\')) {
        // The modern FQCN-id style provides itself.
        $class = $id;
      }
      if ($class !== '') {
        $edges[] = ['src' => $node, 'dst' => ltrim($class, '\\'), 'kind' => 'service_class'];
      }
      $arguments = is_array($definition) && is_array($definition['arguments'] ?? NULL) ? $definition['arguments'] : [];
      foreach ($arguments as $argument) {
        if (is_string($argument) && str_starts_with($argument, '@')) {
          $target = ltrim(substr($argument, 1), '?');
          if ($target !== '') {
            $edges[] = ['src' => $node, 'dst' => 'service:' . $target, 'kind' => 'injects'];
          }
        }
      }
    }
    return ['symbols' => $symbols, 'edges' => $edges];
  }

  /**
   * Extracts route pseudo-symbols + routes_to edges.
   *
   * @param string $content
   *   The file content.
   * @param string $file
   *   The file path.
   * @param string $module
   *   The owning extension.
   *
   * @return array{symbols: array<int, array{fqcn: string, kind: string, file: string, line: int, module: string}>, edges: array<int, array{src: string, dst: string, kind: string}>}
   *   The rows.
   */
  private function fromRouting(string $content, string $file, string $module): array {
    $data = self::decode($content);
    $symbols = [];
    $edges = [];
    foreach ($data as $name => $route) {
      // Only real route entries (route_callbacks etc. have no path).
      if (!is_string($name) || !is_array($route) || !isset($route['path'])) {
        continue;
      }
      $node = 'route:' . $name;
      $symbols[] = [
        'fqcn' => $node,
        'kind' => 'route',
        'file' => $file,
        'line' => self::lineOf($content, $name),
        'module' => $module,
      ];
      $defaults = is_array($route['defaults'] ?? NULL) ? $route['defaults'] : [];
      $controller = is_string($defaults['_controller'] ?? NULL) ? $defaults['_controller'] : '';
      if ($controller !== '') {
        $target = ltrim($controller, '\\');
        if (str_contains($target, '::')) {
          // Class::method controller — the destination is the method.
          $dst = $target;
        }
        elseif (str_contains($target, ':')) {
          // Service controller "service_id:method": a single colon is Drupal's
          // service notation, not "::__invoke" — route to the service symbol.
          $dst = 'service:' . substr($target, 0, (int) strpos($target, ':'));
        }
        else {
          // A bare class name is an invokable controller.
          $dst = $target . '::__invoke';
        }
        $edges[] = ['src' => $node, 'dst' => $dst, 'kind' => 'routes_to'];
        continue;
      }
      $form = is_string($defaults['_form'] ?? NULL) ? $defaults['_form'] : '';
      if ($form !== '') {
        $edges[] = ['src' => $node, 'dst' => ltrim($form, '\\'), 'kind' => 'routes_to'];
      }
    }
    return ['symbols' => $symbols, 'edges' => $edges];
  }

  /**
   * Decodes yaml defensively.
   *
   * @param string $content
   *   The file content.
   *
   * @return array<mixed, mixed>
   *   The decoded map, or [] on malformed input.
   */
  private static function decode(string $content): array {
    try {
      $data = Yaml::decode($content);
    }
    catch (\Throwable) {
      return [];
    }
    return is_array($data) ? $data : [];
  }

  /**
   * Best-effort 1-based line of a top-level key in the raw text.
   *
   * @param string $content
   *   The raw file content.
   * @param string $key
   *   The key to locate.
   *
   * @return int
   *   The line number, or 0 when not found.
   */
  private static function lineOf(string $content, string $key): int {
    // Match the key at the start of any line, allowing leading indentation —
    // service ids sit two spaces under `services:`, so a column-0-only search
    // (strpos "\n$key:") never finds them and yields line 0. preg_quote guards
    // the "." and "\" in ids like "fx.worker" / "Drupal\fx\SelfNamed".
    if (preg_match('/^[ \t]*' . preg_quote($key, '/') . ':/m', $content, $m, PREG_OFFSET_CAPTURE)) {
      return substr_count($content, "\n", 0, (int) $m[0][1]) + 1;
    }
    return 0;
  }

}
