<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Chunker;

use Droost\Engine\Search\Chunk;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects symbol chunks from a parsed PHP AST.
 *
 * Captures classes, interfaces, traits, enums, functions, and methods. Expects
 * a NameResolver to run first so class-like and function nodes carry their
 * fully-qualified `namespacedName`.
 */
final class SymbolCollector extends NodeVisitorAbstract {

  /**
   * The collected chunks.
   *
   * @var array<int, \Droost\Engine\Search\Chunk>
   */
  public array $chunks = [];

  /**
   * Stack of enclosing class-like FQCNs, to qualify methods.
   *
   * @var array<int, string>
   */
  private array $classStack = [];

  /**
   * Constructs a SymbolCollector.
   *
   * @param string $file
   *   The file path (relative to the app root) being parsed.
   * @param string $module
   *   The owning extension machine name.
   */
  public function __construct(
    private readonly string $file,
    private readonly string $module,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enterNode(Node $node): ?int {
    if ($node instanceof ClassLike) {
      $fqcn = $this->fqcn($node);
      $this->classStack[] = $fqcn;
      if ($fqcn !== '') {
        $this->add($this->classKind($node), $fqcn, $node);
      }
    }
    elseif ($node instanceof Function_) {
      $fqcn = $this->fqcn($node);
      if ($fqcn !== '') {
        $this->add('function', $fqcn, $node);
      }
    }
    elseif ($node instanceof ClassMethod) {
      $class = $this->classStack === [] ? '' : (string) end($this->classStack);
      if ($class !== '') {
        $this->add('method', $class . '::' . $node->name->toString(), $node);
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function leaveNode(Node $node): ?int {
    if ($node instanceof ClassLike) {
      array_pop($this->classStack);
    }
    return NULL;
  }

  /**
   * Returns the fully-qualified name of a class-like or function node.
   *
   * @param \PhpParser\Node\Stmt\ClassLike|\PhpParser\Node\Stmt\Function_ $node
   *   The node.
   *
   * @return string
   *   The FQCN, or '' for an anonymous class.
   */
  private function fqcn(ClassLike|Function_ $node): string {
    return $node->namespacedName?->toString() ?? '';
  }

  /**
   * Maps a class-like node to a kind label.
   *
   * @param \PhpParser\Node\Stmt\ClassLike $node
   *   The node.
   *
   * @return string
   *   One of class|interface|trait|enum.
   */
  private function classKind(ClassLike $node): string {
    return match (TRUE) {
      $node instanceof Interface_ => 'interface',
      $node instanceof Trait_ => 'trait',
      $node instanceof Enum_ => 'enum',
      default => 'class',
    };
  }

  /**
   * Records a symbol chunk.
   *
   * @param string $kind
   *   The symbol kind.
   * @param string $ref
   *   The FQCN (or FQCN::method) identifying the symbol.
   * @param \PhpParser\Node $node
   *   The node, for docblock and line number.
   */
  private function add(string $kind, string $ref, Node $node): void {
    $doc = $node->getDocComment();
    $docText = $doc !== NULL ? self::cleanDoc($doc->getText()) : '';
    $this->chunks[] = new Chunk(
      'php_symbol',
      $ref,
      trim($kind . ' ' . $ref . "\n" . $docText),
      [
        'kind' => $kind,
        'name' => $ref,
        'file' => $this->file,
        'line' => $node->getStartLine(),
        'module' => $this->module,
      ],
    );
  }

  /**
   * Strips comment syntax from a docblock to plain text.
   *
   * @param string $raw
   *   The raw docblock including delimiters.
   *
   * @return string
   *   The cleaned text.
   */
  private static function cleanDoc(string $raw): string {
    $body = preg_replace('#^\s*/\*\*?|\*/\s*$#', '', $raw) ?? $raw;
    $out = [];
    foreach (preg_split('/\R/', $body) ?: [] as $line) {
      $clean = trim(preg_replace('/^\s*\*\s?/', '', $line) ?? $line);
      if ($clean !== '') {
        $out[] = $clean;
      }
    }
    return implode("\n", $out);
  }

}
