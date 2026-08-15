<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Graph;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects code-graph symbols and edges from a parsed PHP AST.
 *
 * Syntactic edges: extends, implements, uses (trait), instantiates (new X),
 * calls (X::y static). Framework-semantic edges: uses_service
 * (\Drupal::service('literal') → "service:<id>"), implements_hook (#[Hook]
 * attributes always; procedural {module}_{hook} functions ONLY when the hook
 * name exists in the injected api.php-derived universe — precision over
 * recall, so helper functions never masquerade as hooks), derived_by (a
 * "deriver" attribute argument on a plugin class). Expects NameResolver to
 * have run so referenced names are fully qualified. Instance-method / DI
 * call resolution (which needs type inference) is intentionally out of scope.
 */
final class GraphVisitor extends NodeVisitorAbstract {

  /**
   * Collected symbol rows.
   *
   * @var array<int, array{fqcn: string, kind: string, file: string, line: int, module: string}>
   */
  public array $symbols = [];

  /**
   * Collected edge rows.
   *
   * @var array<int, array{src: string, dst: string, kind: string}>
   */
  public array $edges = [];

  /**
   * Stack of enclosing class-like FQCNs (to qualify methods and trait-use).
   *
   * @var array<int, string>
   */
  private array $classStack = [];

  /**
   * Stack of enclosing symbol FQCNs (the edge source for new/static calls).
   *
   * @var array<int, string>
   */
  private array $contextStack = [];

  /**
   * Constructs a GraphVisitor.
   *
   * @param string $file
   *   The file path being parsed.
   * @param string $module
   *   The owning extension.
   * @param array<string, true> $hookNames
   *   The api.php-derived hook-name set gating procedural hook edges.
   */
  public function __construct(
    private readonly string $file,
    private readonly string $module,
    private readonly array $hookNames = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enterNode(Node $node): ?int {
    if ($node instanceof ClassLike) {
      $this->enterClassLike($node);
    }
    elseif ($node instanceof Function_) {
      $fqcn = $node->namespacedName?->toString() ?? '';
      $this->pushSymbol($fqcn, 'function', $node->getStartLine());
      $this->hookAttributeEdges($fqcn, $node->attrGroups);
      $this->proceduralHookEdge($fqcn, $node->name->toString());
    }
    elseif ($node instanceof ClassMethod) {
      $class = $this->top($this->classStack);
      $fqcn = $class === '' ? '' : $class . '::' . $node->name->toString();
      $this->pushSymbol($fqcn, 'method', $node->getStartLine());
      $this->hookAttributeEdges($fqcn, $node->attrGroups);
    }
    elseif ($node instanceof TraitUse) {
      $this->traitUse($node);
    }
    elseif ($node instanceof New_) {
      $this->callEdge($node->class, 'instantiates');
    }
    elseif ($node instanceof StaticCall) {
      $this->staticCall($node);
    }
    return NULL;
  }

  /**
   * Records a "calls" edge for a static call, ignoring first-class callables.
   *
   * `Foo::bar(...)` creates a Closure rather than calling the method, so it is
   * not a call edge.
   *
   * @param \PhpParser\Node\Expr\StaticCall $node
   *   The static-call node.
   */
  private function staticCall(StaticCall $node): void {
    $args = $node->args;
    if (count($args) === 1 && $args[0] instanceof VariadicPlaceholder) {
      return;
    }
    $this->callEdge($node->class, 'calls');
    $this->serviceLiteralEdge($node);
  }

  /**
   * Records a "uses_service" edge for \Drupal::service('<literal>').
   *
   * Only a literal string argument produces the edge — a dynamic id cannot
   * be resolved statically and guessing would corrupt the graph. The coarse
   * "calls → Drupal" edge above is retained unchanged. The service-id target
   * uses the "service:" pseudo-symbol namespace the YAML extractor also
   * writes, so DI declarations and by-id lookups meet on one node.
   *
   * @param \PhpParser\Node\Expr\StaticCall $node
   *   The static-call node.
   */
  private function serviceLiteralEdge(StaticCall $node): void {
    $src = $this->top($this->contextStack);
    if ($src === ''
      || !$node->class instanceof Name
      || $node->class->toString() !== 'Drupal'
      || !$node->name instanceof Identifier
      || $node->name->toString() !== 'service') {
      return;
    }
    $first = $node->args[0] ?? NULL;
    if ($first instanceof Arg && $first->value instanceof String_ && $first->value->value !== '') {
      $this->edges[] = ['src' => $src, 'dst' => 'service:' . $first->value->value, 'kind' => 'uses_service'];
    }
  }

  /**
   * Records "implements_hook" edges for #[Hook('name')] attributes.
   *
   * @param string $fqcn
   *   The attributed symbol (the edge source).
   * @param array<\PhpParser\Node\AttributeGroup> $groups
   *   The symbol's attribute groups.
   */
  private function hookAttributeEdges(string $fqcn, array $groups): void {
    if ($fqcn === '') {
      return;
    }
    foreach ($groups as $group) {
      foreach ($group->attrs as $attribute) {
        $name = $attribute->name->toString();
        if ($name !== 'Hook' && !str_ends_with($name, '\\Hook')) {
          continue;
        }
        $first = $attribute->args[0] ?? NULL;
        if ($first instanceof Arg && $first->value instanceof String_ && $first->value->value !== '') {
          $this->edges[] = ['src' => $fqcn, 'dst' => 'hook:' . $first->value->value, 'kind' => 'implements_hook'];
        }
      }
    }
  }

  /**
   * Records an "implements_hook" edge for a {module}_{hook} function.
   *
   * Gated on the api.php-derived hook-name universe: a function whose suffix
   * is not a documented hook (e.g. mymodule_helper) emits nothing.
   *
   * @param string $fqcn
   *   The function symbol (the edge source).
   * @param string $localName
   *   The function's unqualified name.
   */
  private function proceduralHookEdge(string $fqcn, string $localName): void {
    if ($fqcn === '' || $this->module === '' || $this->hookNames === []) {
      return;
    }
    $prefix = $this->module . '_';
    if (!str_starts_with($localName, $prefix)) {
      return;
    }
    $hook = substr($localName, strlen($prefix));
    if ($hook !== '' && isset($this->hookNames[$hook])) {
      $this->edges[] = ['src' => $fqcn, 'dst' => 'hook:' . $hook, 'kind' => 'implements_hook'];
    }
  }

  /**
   * Records a "derived_by" edge when an attribute carries a deriver arg.
   *
   * @param string $fqcn
   *   The plugin class (the edge source).
   * @param array<\PhpParser\Node\AttributeGroup> $groups
   *   The class's attribute groups.
   */
  private function deriverEdges(string $fqcn, array $groups): void {
    if ($fqcn === '') {
      return;
    }
    foreach ($groups as $group) {
      foreach ($group->attrs as $attribute) {
        foreach ($attribute->args as $arg) {
          if (!$arg->name instanceof Identifier || $arg->name->toString() !== 'deriver') {
            continue;
          }
          $value = $arg->value;
          $target = '';
          if ($value instanceof ClassConstFetch && $value->class instanceof Name) {
            $target = $value->class->toString();
          }
          elseif ($value instanceof String_) {
            $target = ltrim($value->value, '\\');
          }
          if ($target !== '') {
            $this->edges[] = ['src' => $fqcn, 'dst' => $target, 'kind' => 'derived_by'];
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function leaveNode(Node $node): ?int {
    if ($node instanceof ClassLike) {
      // deriverEdges runs on LEAVE, not enter: only by now has NameResolver
      // descended into and resolved the deriver attribute's argument to a
      // fully-qualified name. On enter it is still the bare short name
      // ("SemDeriver" rather than "Drupal\...\SemDeriver").
      $fqcn = $node->namespacedName?->toString() ?? '';
      if ($fqcn !== '') {
        $this->deriverEdges($fqcn, $node->attrGroups);
      }
      array_pop($this->classStack);
      array_pop($this->contextStack);
    }
    elseif ($node instanceof Function_ || $node instanceof ClassMethod) {
      array_pop($this->contextStack);
    }
    return NULL;
  }

  /**
   * Records a class-like symbol and its inheritance edges.
   *
   * @param \PhpParser\Node\Stmt\ClassLike $node
   *   The class-like node.
   */
  private function enterClassLike(ClassLike $node): void {
    $fqcn = $node->namespacedName?->toString() ?? '';
    $this->classStack[] = $fqcn;
    $kind = match (TRUE) {
      $node instanceof Interface_ => 'interface',
      $node instanceof Trait_ => 'trait',
      $node instanceof Enum_ => 'enum',
      default => 'class',
    };
    $this->pushSymbol($fqcn, $kind, $node->getStartLine());
    if ($fqcn === '') {
      return;
    }
    // A class-level (invokable) #[Hook] implements a hook exactly as a method-
    // level one does; enterNode() only scans methods, so scan the class here.
    $this->hookAttributeEdges($fqcn, $node->attrGroups);
    // deriverEdges is emitted in leaveNode (see below) — the attribute argument
    // is not yet name-resolved at this point.
    if ($node instanceof Class_) {
      if ($node->extends instanceof Name) {
        $this->edges[] = ['src' => $fqcn, 'dst' => $node->extends->toString(), 'kind' => 'extends'];
      }
      $this->addNameEdges($fqcn, $node->implements, 'implements');
    }
    elseif ($node instanceof Interface_) {
      $this->addNameEdges($fqcn, $node->extends, 'extends');
    }
    elseif ($node instanceof Enum_) {
      // Enums commonly implement interfaces (e.g. Drupal plugin enums).
      $this->addNameEdges($fqcn, $node->implements, 'implements');
    }
  }

  /**
   * Records "uses" edges for a trait-use statement.
   *
   * @param \PhpParser\Node\Stmt\TraitUse $node
   *   The trait-use node.
   */
  private function traitUse(TraitUse $node): void {
    $class = $this->top($this->classStack);
    if ($class !== '') {
      $this->addNameEdges($class, $node->traits, 'uses');
    }
  }

  /**
   * Records a call/instantiate edge from the current context to a class name.
   *
   * @param \PhpParser\Node|string $class
   *   The class operand of a new/static-call expression.
   * @param string $kind
   *   The edge kind.
   */
  private function callEdge(Node|string $class, string $kind): void {
    $src = $this->top($this->contextStack);
    if ($src === '' || !$class instanceof Name) {
      return;
    }
    $dst = $class->toString();
    // NameResolver leaves self/static/parent unresolved. Resolve self/static
    // to the enclosing class; drop parent (its target is the base class, not
    // tracked here) — storing the literal keyword would create a bogus
    // "self"/"parent"/"static" node and corrupt callers()/callees().
    $keyword = strtolower($dst);
    if ($keyword === 'self' || $keyword === 'static') {
      $dst = $this->top($this->classStack);
    }
    elseif ($keyword === 'parent') {
      return;
    }
    if ($dst !== '') {
      $this->edges[] = ['src' => $src, 'dst' => $dst, 'kind' => $kind];
    }
  }

  /**
   * Adds edges from a source to each name in a list.
   *
   * @param string $src
   *   The source FQCN.
   * @param array<\PhpParser\Node\Name> $names
   *   The target names.
   * @param string $kind
   *   The edge kind.
   */
  private function addNameEdges(string $src, array $names, string $kind): void {
    foreach ($names as $name) {
      $this->edges[] = ['src' => $src, 'dst' => $name->toString(), 'kind' => $kind];
    }
  }

  /**
   * Records a symbol and pushes it as the current edge-source context.
   *
   * @param string $fqcn
   *   The symbol FQCN ('' is recorded as context only, not as a symbol).
   * @param string $kind
   *   The symbol kind.
   * @param int $line
   *   The start line.
   */
  private function pushSymbol(string $fqcn, string $kind, int $line): void {
    $this->contextStack[] = $fqcn;
    if ($fqcn !== '') {
      $this->symbols[] = [
        'fqcn' => $fqcn,
        'kind' => $kind,
        'file' => $this->file,
        'line' => $line,
        'module' => $this->module,
      ];
    }
  }

  /**
   * Returns the top of a string stack, or ''.
   *
   * @param array<int, string> $stack
   *   The stack.
   *
   * @return string
   *   The top element, or '' when empty.
   */
  private function top(array $stack): string {
    return $stack === [] ? '' : (string) end($stack);
  }

}
