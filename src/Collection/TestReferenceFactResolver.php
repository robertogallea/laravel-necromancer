<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

/**
 * Adds the `references` Discovered Fact to already-collected test
 * artifacts by reading their source file (ADR 0024): the application
 * classes the test uses in code and the route names it passes to
 * `route()`.
 */
final class TestReferenceFactResolver
{
    public const CLASS_REFERENCE = 'class';

    public const ROUTE_REFERENCE = 'route';

    /**
     * @param  string  $appNamespace  the application namespace (e.g. `App\`); only classes under it are recorded
     */
    public function __construct(
        private readonly string $appNamespace,
        private readonly SourceFileParser $sources = new SourceFileParser,
    ) {}

    /**
     * @param  array<string, list<array<string, mixed>>>  $artifacts
     * @return array{0: array<string, list<array<string, mixed>>>, 1: list<string>}
     */
    public function apply(array $artifacts): array
    {
        $diagnostics = [];

        foreach ($artifacts['tests'] ?? [] as $position => $test) {
            $file = $test['source']['file'] ?? null;

            if (! is_string($file)) {
                continue;
            }

            try {
                $statements = $this->sources->parse($file);
            } catch (Error $error) {
                $label = $test['id'] ?? $file;
                $diagnostics[] = "TR_PARSE_FAILED: {$label} source '{$file}' could not be parsed "
                    ."({$error->getMessage()}); its references were not collected.";

                continue;
            }

            if ($statements === null) {
                continue;
            }

            $references = $this->referencesIn($statements);

            if ($references !== []) {
                $artifacts['tests'][$position]['references'] = $references;
            }
        }

        return [$artifacts, $diagnostics];
    }

    /**
     * Deduplicated and sorted by kind, then target.
     *
     * @param  array<Node>  $statements
     * @return list<array{kind: string, target: string}>
     */
    private function referencesIn(array $statements): array
    {
        $references = [];

        foreach ((new NodeFinder)->find($statements, fn (Node $node): bool => true) as $node) {
            $reference = $this->classReference($node) ?? $this->routeReference($node);

            if ($reference !== null) {
                $references[$reference['kind']."\0".$reference['target']] = $reference;
            }
        }

        ksort($references, SORT_STRING);

        return array_values($references);
    }

    /**
     * `X::class`, `new X(...)`, or `X::method(...)` naming an application
     * class; a `use` import alone never counts.
     *
     * @return array{kind: string, target: string}|null
     */
    private function classReference(Node $node): ?array
    {
        $class = match (true) {
            $node instanceof ClassConstFetch => $node->name instanceof Identifier && $node->name->toLowerString() === 'class' ? $node->class : null,
            $node instanceof New_, $node instanceof StaticCall => $node->class,
            default => null,
        };

        if (! $class instanceof Name || $class->isSpecialClassName()) {
            return null;
        }

        $name = ltrim($class->toString(), '\\');

        return str_starts_with($name, $this->appNamespace) ? ['kind' => self::CLASS_REFERENCE, 'target' => $name] : null;
    }

    /**
     * `route('literal.name', ...)`; a dynamic or interpolated name is skipped.
     *
     * @return array{kind: string, target: string}|null
     */
    private function routeReference(Node $node): ?array
    {
        if (! $node instanceof FuncCall || ! $node->name instanceof Name || $node->isFirstClassCallable()
            || strtolower(ltrim($node->name->toString(), '\\')) !== 'route') {
            return null;
        }

        $name = $node->getArgs()[0]->value ?? null;

        return $name instanceof String_ && $name->value !== '' ? ['kind' => self::ROUTE_REFERENCE, 'target' => $name->value] : null;
    }
}
