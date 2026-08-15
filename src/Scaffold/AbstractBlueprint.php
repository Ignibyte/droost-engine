<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold;

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
    $path = $context->appRoot . '/' . $relative;
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

}
