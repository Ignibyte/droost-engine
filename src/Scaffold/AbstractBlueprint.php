<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold;

use Droost\Engine\Support\ProjectRoot;

/**
 * Shared file-writing helpers for blueprints (dry-run + skip-if-exists aware).
 */
abstract class AbstractBlueprint implements BlueprintInterface {

  /**
   * Lowercase PHP keywords that are illegal as a class name.
   *
   * `final class List {}` (etc.) is a parse error, so className() rejects these
   * rather than emitting a module that will not parse. ("enum" is legal as a
   * class name and is intentionally absent.)
   *
   * @var array<int, string>
   */
  private const array RESERVED_CLASS_NAMES = [
    // Statement / expression keywords.
    'list', 'match', 'class', 'echo', 'static', 'print', 'for', 'foreach',
    'default', 'function', 'use', 'namespace', 'fn', 'readonly', 'array',
    'trait', 'interface', 'if', 'else', 'elseif', 'while', 'do', 'switch',
    'case', 'break', 'continue', 'return', 'new', 'clone', 'try', 'catch',
    'finally', 'throw', 'global', 'goto', 'instanceof', 'insteadof', 'abstract',
    'final', 'private', 'protected', 'public', 'const', 'as', 'yield', 'exit',
    'die', 'include', 'require', 'and', 'or', 'xor', 'isset', 'unset', 'empty',
    'eval', 'extends', 'implements', 'declare', 'enddeclare', 'endfor',
    'endforeach', 'endif', 'endswitch', 'endwhile', 'var', 'include_once',
    'require_once',
    // Reserved type keywords, also illegal as class names ("enum" is legal).
    'self', 'parent', 'iterable', 'object', 'mixed', 'void', 'never', 'null',
    'false', 'true', 'bool', 'int', 'float', 'string', 'callable',
  ];

  /**
   * Writes a generated file, honouring dry-run and never overwriting.
   *
   * @param \Droost\Engine\Scaffold\ScaffoldContext $context
   *   The scaffold context.
   * @param string $relative
   *   The path relative to the app root.
   * @param string $content
   *   The file content.
   * @param \Droost\Engine\Scaffold\ScaffoldResult $result
   *   The result accumulator.
   *
   * @throws \RuntimeException
   *   When the target directory or file cannot be written.
   */
  protected function writeFile(ScaffoldContext $context, string $relative, string $content, ScaffoldResult $result): void {
    $this->write($context, $context->appRoot . '/' . $relative, $relative, $content, $result);
  }

  /**
   * Writes a generated file at the PROJECT root, above the docroot.
   *
   * Most generated files belong inside the Drupal app root, which is what
   * writeFile() assumes. A few do not: a Drupal recipe lives at
   * `<project>/recipes/<name>/`, a sibling of the docroot rather than
   * something inside it, and writing one into the docroot produces a recipe
   * Drupal will never find.
   *
   * The reported path is relative to the APP root either way, so a project
   * file shows as `../recipes/…` and is never mistaken for a docroot path.
   * On layouts where Drupal is itself the project root, ProjectRoot falls
   * back to the app root and the two are the same place.
   *
   * @param \Droost\Engine\Scaffold\ScaffoldContext $context
   *   The scaffold context.
   * @param string $relative
   *   The path relative to the project root.
   * @param string $content
   *   The file content.
   * @param \Droost\Engine\Scaffold\ScaffoldResult $result
   *   The result accumulator.
   *
   * @throws \RuntimeException
   *   When the target directory or file cannot be written.
   */
  protected function writeProjectFile(ScaffoldContext $context, string $relative, string $content, ScaffoldResult $result): void {
    $relative = ltrim($relative, '/');
    $projectRoot = (new ProjectRoot($context->appRoot))->path();
    $label = $projectRoot === $context->appRoot ? $relative : '../' . $relative;
    $this->write($context, $projectRoot . '/' . $relative, $label, $content, $result);
  }

  /**
   * Writes one file, honouring dry-run and never overwriting.
   *
   * @param \Droost\Engine\Scaffold\ScaffoldContext $context
   *   The scaffold context.
   * @param string $path
   *   The absolute path to write.
   * @param string $relative
   *   The app-root-relative path, for reporting.
   * @param string $content
   *   The file content.
   * @param \Droost\Engine\Scaffold\ScaffoldResult $result
   *   The result accumulator.
   *
   * @throws \RuntimeException
   *   When the target directory or file cannot be written.
   */
  private function write(ScaffoldContext $context, string $path, string $relative, string $content, ScaffoldResult $result): void {
    if (is_file($path)) {
      $result->addSkipped($relative);
      return;
    }
    if ($context->dryRun) {
      $result->addCreated($relative);
      return;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException(sprintf('Could not create directory: %s', $dir));
    }
    if (file_put_contents($path, rtrim($content, "\n") . "\n") === FALSE) {
      throw new \RuntimeException(sprintf('Could not write file: %s', $relative));
    }
    // Record success only after the bytes are on disk, so a failed write is
    // never reported as "created".
    $result->addCreated($relative);
  }

  /**
   * Converts a machine name to PascalCase (e.g. "my_tool" -> "MyTool").
   *
   * @param string $value
   *   The machine name.
   *
   * @return string
   *   The PascalCase form.
   */
  protected function pascalCase(string $value): string {
    return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value)));
  }

  /**
   * Sanitises a value to a Drupal machine name.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   Lowercase a-z0-9_ only.
   */
  protected function machineName(string $value): string {
    return preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace('-', '_', $value))) ?? '';
  }

  /**
   * Derives a valid PHP class name (PascalCase identifier), or '' if none.
   *
   * Strips anything that is not a letter, digit, or underscore, then trims
   * leading characters that cannot start an identifier (digits/underscores),
   * so the result is always a syntactically valid class name or empty.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   A valid class name, or '' when nothing usable remains.
   */
  protected function className(string $value): string {
    $pascal = $this->pascalCase($value);
    $clean = preg_replace('/[^A-Za-z0-9_]/', '', $pascal) ?? '';
    $clean = ltrim($clean, '0123456789_');
    // Reject PHP keywords that cannot be class names, so the caller surfaces a
    // clear "could not derive a class" error instead of a parse-error module.
    return in_array(strtolower($clean), self::RESERVED_CLASS_NAMES, TRUE) ? '' : $clean;
  }

  /**
   * Escapes a value for a PHP single-quoted string literal.
   *
   * Control characters (which a single-quoted literal would carry verbatim)
   * are stripped first so the value stays on one source line.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   The escaped value (backslashes and single quotes).
   */
  protected function phpString(string $value): string {
    $clean = preg_replace('/[\x00-\x1f\x7f]/', '', $value) ?? '';
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $clean);
  }

  /**
   * Makes a value safe to embed inside a PHP docblock comment.
   *
   * Strips control characters and neutralises the comment terminator so the
   * value cannot close the docblock and inject code after it.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   The comment-safe value.
   */
  protected function docText(string $value): string {
    $clean = preg_replace('/[\x00-\x1f\x7f]/', '', $value) ?? '';
    return str_replace('*/', '* /', $clean);
  }

  /**
   * Normalises a --modules CSV into a test $modules chain.
   *
   * Order is preserved because tests install modules in the order given,
   * and the target module is appended rather than prepended so its
   * dependencies still come first.
   *
   * @param string $csv
   *   The raw comma-separated option value.
   * @param string $module
   *   The target module, which must appear in the list.
   *
   * @return array<int, string>
   *   The machine names to install, in order, without duplicates.
   */
  protected function moduleList(string $csv, string $module): array {
    $names = [];
    foreach (explode(',', $csv) as $candidate) {
      $name = $this->machineName(trim($candidate));
      // machineName() maps "-" to "_" before filtering, so punctuation-only
      // entries survive as "_" or "___", and digits survive as "123". Neither
      // can name a module: the test runner throws "Unavailable module" at
      // boot, far from the typo. Require a real machine name instead.
      if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
        continue;
      }
      if (!in_array($name, $names, TRUE)) {
        $names[] = $name;
      }
    }
    // Omitting the module under test is the classic mistake, and it fails at
    // runtime far from the cause — so append it rather than trusting the list.
    if (!in_array($module, $names, TRUE)) {
      $names[] = $module;
    }
    return $names;
  }

}
