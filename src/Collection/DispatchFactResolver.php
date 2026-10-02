<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Adds the `dispatches` Discovered Fact to already-collected class-backed
 * artifacts by reading their own source file (ADR 0020).
 */
final class DispatchFactResolver
{
    private const QUEUED = 'queued';

    private const SYNC = 'sync';

    private const FACADES = ['Bus', 'Event', 'Mail'];

    /**
     * The artifact types whose `class` names a class written in their own
     * source file. Routes, tests, gates and scheduled tasks are not
     * class-backed, even when one of them happens to carry a `class`.
     */
    private const CLASS_BACKED_TYPES = [
        'controllers', 'models', 'form_requests', 'actions', 'jobs', 'events', 'listeners',
        'commands', 'policies', 'enums', 'observers', 'middleware', 'livewire_components',
        'mailables', 'validation_rules', 'service_providers',
    ];

    private Parser $parser;

    /**
     * Name-resolved statements per source path, so middleware registered
     * several times is parsed once; `null` marks a file that failed to parse.
     *
     * @var array<string, array<Node>|null>
     */
    private array $parsed = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $artifacts
     * @return array{0: array<string, list<array<string, mixed>>>, 1: list<string>}
     */
    public function apply(array $artifacts): array
    {
        $diagnostics = [];

        foreach ($artifacts as $type => $items) {
            if (! in_array($type, self::CLASS_BACKED_TYPES, true)) {
                continue;
            }

            foreach ($items as $position => $item) {
                $class = $item['class'] ?? null;
                $file = $item['source']['file'] ?? null;

                if (! is_string($class) || ! is_string($file)) {
                    continue;
                }

                $statements = $this->statements($file, $item['id'] ?? $class, $diagnostics);
                $dispatches = $statements !== null ? $this->dispatchesOf($class, $statements) : [];

                if ($dispatches !== []) {
                    $artifacts[$type][$position]['dispatches'] = $dispatches;
                }
            }
        }

        return [$artifacts, $diagnostics];
    }

    /**
     * @param  list<string>  $diagnostics
     * @return array<Node>|null
     */
    private function statements(string $file, string $artifactLabel, array &$diagnostics): ?array
    {
        $path = str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : base_path($file);

        if (! array_key_exists($path, $this->parsed)) {
            $source = is_file($path) ? file_get_contents($path) : false;

            try {
                $this->parsed[$path] = $source === false
                    ? null
                    : (new NodeTraverser(new NameResolver))->traverse($this->parser->parse($source) ?? []);
            } catch (Error $error) {
                $this->parsed[$path] = null;
                $diagnostics[] = "DS_PARSE_FAILED: {$artifactLabel} source '{$file}' could not be parsed "
                    ."({$error->getMessage()}); its dispatches were not collected.";
            }
        }

        return $this->parsed[$path];
    }

    /**
     * @param  array<Node>  $statements
     * @return list<array{target: string, method: string, mode: ?string}>
     */
    private function dispatchesOf(string $class, array $statements): array
    {
        $finder = new NodeFinder;
        $classNode = $finder->findFirst($statements, fn (Node $node): bool => $node instanceof ClassLike
            && $node->namespacedName?->toString() === ltrim($class, '\\'));

        if (! $classNode instanceof ClassLike) {
            return [];
        }

        $dispatches = [];

        foreach ($classNode->getMethods() as $method) {
            foreach ($finder->findInstanceOf($method->stmts ?? [], CallLike::class) as $call) {
                foreach ($this->dispatchedBy($call) as [$target, $mode]) {
                    $dispatches[] = ['target' => $target, 'method' => $method->name->toString(), 'mode' => $mode];
                }
            }
        }

        return $this->canonical($dispatches);
    }

    /**
     * The `[target, mode]` pairs a single call dispatches; empty when the call
     * isn't a recognized dispatch shape or its target isn't a class name.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private function dispatchedBy(CallLike $call): array
    {
        // Only a single-part name is Laravel's global helper: a namespaced
        // function (or one imported with `use function`) merely shares its name.
        if ($call instanceof FuncCall && $call->name instanceof Name && count($call->name->getParts()) === 1) {
            $mode = match ($call->name->toString()) {
                'dispatch' => self::QUEUED,
                'dispatch_sync' => self::SYNC,
                'event', 'broadcast' => null,
                default => false,
            };

            return $mode === false ? [] : $this->withMode($this->newTargets($call, 0), $mode);
        }

        if ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier) {
            return $this->fromStaticCall($call->class, $call->name->toString(), $call);
        }

        if ($call instanceof MethodCall && $call->name instanceof Identifier) {
            return $this->fromMethodCall($call, $call->name->toString());
        }

        return [];
    }

    /**
     * @return list<array{0: string, 1: ?string}>
     */
    private function fromStaticCall(Name $class, string $method, StaticCall $call): array
    {
        if ($class->isSpecialClassName()) {
            return [];
        }

        $mode = match ([$this->facade($class), $method]) {
            ['Bus', 'dispatch'] => self::QUEUED,
            ['Bus', 'dispatchSync'] => self::SYNC,
            ['Event', 'dispatch'] => null,
            ['Mail', 'send'] => self::SYNC,
            default => false,
        };

        if ($mode !== false) {
            return $this->withMode($this->newTargets($call, 0), $mode);
        }

        if ($this->facade($class) === 'Bus' && in_array($method, ['chain', 'batch'], true)) {
            return $this->withMode($this->arrayTargets($call), self::QUEUED);
        }

        if ($this->facade($class) !== null) {
            return [];
        }

        $mode = match ($method) {
            'dispatch', 'dispatchIf', 'dispatchUnless' => self::QUEUED,
            'dispatchSync' => self::SYNC,
            default => false,
        };

        return $mode === false ? [] : [[$class->toString(), $mode]];
    }

    /**
     * @return list<array{0: string, 1: ?string}>
     */
    private function fromMethodCall(MethodCall $call, string $method): array
    {
        if ($method === 'dispatch' && $call->var instanceof Variable && $call->var->name === 'this') {
            return $this->withMode($this->newTargets($call, 0), self::QUEUED);
        }

        if (! $this->isPendingMail($call->var)) {
            return [];
        }

        return match ($method) {
            'send' => $this->withMode($this->newTargets($call, 0), self::SYNC),
            'queue' => $this->withMode($this->newTargets($call, 0), self::QUEUED),
            'later' => $this->withMode($this->newTargets($call, 1), self::QUEUED),
            default => [],
        };
    }

    /**
     * Whether an expression is a pending mail chain rooted at `Mail::to()`
     * (or `cc()`/`bcc()`/`locale()`…, any static entry point on the facade).
     */
    private function isPendingMail(Node\Expr $expression): bool
    {
        while ($expression instanceof MethodCall) {
            $expression = $expression->var;
        }

        return $expression instanceof StaticCall
            && $expression->class instanceof Name
            && $this->facade($expression->class) === 'Mail';
    }

    /**
     * The short facade name (`Bus`, `Event`, `Mail`) a class name refers to,
     * whether imported from Illuminate\Support\Facades or written as the
     * global alias.
     */
    private function facade(Name $class): ?string
    {
        $name = ltrim($class->toString(), '\\');

        foreach (self::FACADES as $facade) {
            if ($name === $facade || $name === "Illuminate\\Support\\Facades\\{$facade}") {
                return $facade;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function newTargets(CallLike $call, int $position): array
    {
        if ($call->isFirstClassCallable()) {
            return [];
        }

        $argument = $call->getArgs()[$position] ?? null;
        $target = $argument !== null ? $this->newTarget($argument->value) : null;

        return $target !== null ? [$target] : [];
    }

    /**
     * @return list<string>
     */
    private function arrayTargets(StaticCall $call): array
    {
        if ($call->isFirstClassCallable()) {
            return [];
        }

        $argument = $call->getArgs()[0] ?? null;

        if ($argument === null || ! $argument->value instanceof Array_) {
            return [];
        }

        $targets = [];

        foreach ($argument->value->items as $item) {
            $target = $this->newTarget($item->value);

            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    private function newTarget(Node\Expr $expression): ?string
    {
        if (! $expression instanceof New_ || ! $expression->class instanceof Name || $expression->class->isSpecialClassName()) {
            return null;
        }

        return $expression->class->toString();
    }

    /**
     * @param  list<string>  $targets
     * @return list<array{0: string, 1: ?string}>
     */
    private function withMode(array $targets, ?string $mode): array
    {
        return array_map(fn (string $target): array => [$target, $mode], $targets);
    }

    /**
     * Deduplicated on (method, target, mode) and sorted by (method, target).
     *
     * @param  list<array{target: string, method: string, mode: ?string}>  $dispatches
     * @return list<array{target: string, method: string, mode: ?string}>
     */
    private function canonical(array $dispatches): array
    {
        $unique = [];

        foreach ($dispatches as $dispatch) {
            $unique[$dispatch['method']."\0".$dispatch['target']."\0".($dispatch['mode'] ?? '')] = $dispatch;
        }

        $unique = array_values($unique);
        usort($unique, fn (array $a, array $b): int => [$a['method'], $a['target'], $a['mode'] ?? ''] <=> [$b['method'], $b['target'], $b['mode'] ?? '']);

        return $unique;
    }
}
