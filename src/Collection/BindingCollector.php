<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Closure;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LaravelNecromancer\Manifest\SourceLocation;
use LaravelNecromancer\Manifest\StructuralArtifact;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Reads the application's Bindings from the booted container (ADR 0025).
 * Nothing is resolved and no factory closure is ever called: a concrete is
 * learned from the class Laravel captured, a closure's declared return
 * type, or the class of a stored instance.
 */
final readonly class BindingCollector
{
    /**
     * @param  list<string>  $exclusions  `Str::is()` patterns matched against a binding's abstract
     */
    public function __construct(
        private Application $app,
        private array $exclusions = [],
    ) {}

    /**
     * @param  list<string>  $exclusions
     */
    public function withExclusions(array $exclusions): self
    {
        return new self($this->app, $exclusions);
    }

    /**
     * Artisan loads every deferred provider before a command runs, so an
     * unloaded one is met only by a scan run outside the console kernel. Its
     * abstracts are then known from provides() alone, and their concrete and
     * lifetime only when its properties declare them. An abstract an
     * application deferred provider provides is kept even when neither end
     * is in the application namespace.
     *
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        [$instances, $scoped] = $this->storedInstances();
        [$providers, $deferred] = $this->declaringProviders();
        $entries = [];

        foreach ($this->app->getBindings() as $abstract => $binding) {
            [$concrete, $concreteSource] = $this->concreteOf($binding['concrete'] ?? null);

            $entries[$abstract] = [$concrete, $concreteSource, match (true) {
                in_array($abstract, $scoped, true) => 'scoped',
                ($binding['shared'] ?? false) === true => 'singleton',
                default => 'transient',
            }];
        }

        foreach ($instances as $abstract => $instance) {
            $entries[$abstract] ??= [is_object($instance) ? $instance::class : null, is_object($instance) ? 'instance' : null, 'instance'];
        }

        foreach ($this->app->getDeferredServices() as $abstract => $provider) {
            if (isset($entries[$abstract]) || ! $this->isApplicationClass($provider) || ! class_exists($provider)) {
                continue;
            }

            $defaults = (new ReflectionClass($provider))->getDefaultProperties();
            [$concrete, $lifetime] = $this->declarations($defaults['bindings'] ?? [], $defaults['singletons'] ?? [])[$abstract] ?? [null, null];
            $entries[$abstract] = [$concrete, $concrete !== null ? 'class' : null, $lifetime];
            $providers[$abstract] ??= $provider;
            $deferred[$abstract] = true;
        }

        $collected = [];

        foreach ($entries as $abstract => [$concrete, $concreteSource, $lifetime]) {
            $abstract = (string) $abstract;
            $isDeferred = isset($deferred[$abstract]);

            if ((! $isDeferred && ! $this->isApplicationBinding($abstract, $concrete)) || Str::is($this->exclusions, $abstract)) {
                continue;
            }

            $collected[] = StructuralArtifact::binding(
                abstract: $abstract,
                concrete: $concrete,
                concreteSource: $concreteSource,
                lifetime: $lifetime,
                provider: $providers[$abstract] ?? null,
                deferred: $isDeferred,
                source: $this->sourceOf($concrete),
            );
        }

        return [...$collected, ...$this->contextualBindings()];
    }

    /**
     * Each (Consumer, abstract) pair of the container's contextual map is a
     * Contextual Binding (ADR 0026). A primitive need (`$timeout`) is skipped
     * before its implementation is read: a plain give() stores the resolved
     * value, which may be configuration (ADR 0003).
     *
     * @return list<StructuralArtifact>
     */
    private function contextualBindings(): array
    {
        $collected = [];

        foreach ($this->app instanceof Container ? $this->app->contextual : [] as $consumer => $needs) {
            $consumer = (string) $consumer;

            foreach ($needs as $abstract => $implementation) {
                $abstract = (string) $abstract;

                if (str_starts_with($abstract, '$')) {
                    continue;
                }

                [$concrete, $concreteSource] = match (true) {
                    is_string($implementation) => [ltrim($implementation, '\\'), 'class'],
                    $implementation instanceof Closure => $this->returnTypeOf($implementation),
                    default => [null, null],
                };

                if ((! $this->isApplicationClass($consumer) && ! $this->isApplicationBinding($abstract, $concrete)) || Str::is($this->exclusions, $abstract)) {
                    continue;
                }

                $collected[] = StructuralArtifact::binding(
                    abstract: $abstract,
                    concrete: $concrete,
                    concreteSource: $concreteSource,
                    lifetime: null,
                    source: $this->sourceOf($concrete),
                    consumer: $consumer,
                );
            }
        }

        return $collected;
    }

    /**
     * Laravel resolves `#[Bind]` lazily, the first time an abstract is
     * requested, so these bindings exist only as attributes. They are looked
     * for on the application types the scan's collected facts reach (Action
     * entrypoint and controller action parameters, test class references),
     * by reflection alone: no candidate is instantiated or resolved. Only a
     * Global Binding makes an attribute binding redundant, since a
     * Contextual Binding applies to its Consumer alone (ADR 0026).
     *
     * @param  array<string, list<array<string, mixed>>>  $artifacts  the scan's identified artifacts
     * @return list<StructuralArtifact>
     */
    public function attributeBindings(array $artifacts): array
    {
        $known = array_flip(array_map(
            static fn (array $binding): string => (string) ($binding['abstract'] ?? ''),
            array_filter($artifacts['bindings'] ?? [], static fn (array $binding): bool => ! is_string($binding['consumer'] ?? null)),
        ));
        $collected = [];

        foreach ($this->attributeCandidates($artifacts) as $candidate) {
            if (isset($known[$candidate]) || $this->app->bound($candidate) || Str::is($this->exclusions, $candidate)) {
                continue;
            }

            $reflection = new ReflectionClass($candidate);
            $concrete = $this->attributeConcrete($reflection);

            if ($concrete === null) {
                continue;
            }

            $collected[] = StructuralArtifact::binding(
                abstract: $candidate,
                concrete: $concrete,
                concreteSource: 'attribute',
                lifetime: match (true) {
                    $reflection->getAttributes(Singleton::class) !== [] => 'singleton',
                    $reflection->getAttributes(Scoped::class) !== [] => 'scoped',
                    default => 'transient',
                },
                source: $this->sourceOf($concrete),
            );
        }

        return $collected;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $artifacts
     * @return list<class-string>
     */
    private function attributeCandidates(array $artifacts): array
    {
        $types = [];

        foreach ($artifacts['actions'] ?? [] as $action) {
            foreach ((array) ($action['entrypoints'] ?? []) as $entrypoint) {
                foreach ((array) ($entrypoint['parameters'] ?? []) as $parameter) {
                    $types[] = $parameter['type'] ?? null;
                }
            }
        }

        foreach ($artifacts['controllers'] ?? [] as $controller) {
            foreach ((array) ($controller['actions'] ?? []) as $action) {
                foreach ((array) ($action['parameters'] ?? []) as $parameter) {
                    $types[] = $parameter['type'] ?? null;
                }
            }
        }

        foreach ($artifacts['tests'] ?? [] as $test) {
            foreach ((array) ($test['references'] ?? []) as $reference) {
                if (($reference['kind'] ?? null) === TestReferenceFactResolver::CLASS_REFERENCE) {
                    $types[] = $reference['target'] ?? null;
                }
            }
        }

        $candidates = [];

        foreach ($types as $type) {
            foreach (is_string($type) ? (preg_split('/[|&]/', str_replace(['?', '(', ')'], '', $type)) ?: []) : [] as $class) {
                $class = ltrim($class, '\\');

                if ($this->isApplicationClass($class) && (interface_exists($class) || class_exists($class))) {
                    $candidates[$class] = true;
                }
            }
        }

        $candidates = array_keys($candidates);
        sort($candidates, SORT_STRING);

        return $candidates;
    }

    /**
     * Mirrors the container: the first `#[Bind]` naming the scan's
     * environment wins, else the first wildcard one. `#[BindWhen]` is not
     * evaluated, since its condition is a closure.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function attributeConcrete(ReflectionClass $reflection): ?string
    {
        $wildcard = null;

        foreach ($reflection->getAttributes(Bind::class) as $attribute) {
            $bind = $attribute->newInstance();

            if ($bind->environments === ['*']) {
                $wildcard ??= $bind->concrete;
            } elseif ($this->app->environment($bind->environments)) {
                return ltrim($bind->concrete, '\\');
            }
        }

        return $wildcard === null ? null : ltrim($wildcard, '\\');
    }

    /**
     * Abstract → the application provider that declares it without being
     * executed: its `$bindings`/`$singletons` properties, read from the
     * provider instances the application already holds, or a deferred
     * provider's provides(). Also returns the abstracts a deferred
     * application provider provides.
     *
     * @return array{0: array<string, class-string>, 1: array<string, true>}
     */
    private function declaringProviders(): array
    {
        $declared = [];
        $deferred = [];

        foreach ($this->app->getProviders(ServiceProvider::class) as $provider) {
            if (! $this->isApplicationClass($provider::class)) {
                continue;
            }

            $abstracts = array_keys($this->declarations(
                property_exists($provider, 'bindings') ? $provider->bindings : [],
                property_exists($provider, 'singletons') ? $provider->singletons : [],
            ));

            if ($provider instanceof DeferrableProvider) {
                foreach (array_filter($provider->provides(), 'is_string') as $abstract) {
                    $abstracts[] = $abstract;
                    $deferred[$abstract] = true;
                }
            }

            foreach ($abstracts as $abstract) {
                $declared[(string) $abstract] ??= $provider::class;
            }
        }

        return [$declared, $deferred];
    }

    /**
     * Abstract → [concrete, lifetime] as `Application::register()` would
     * bind a provider's properties; a `$singletons` entry with an integer
     * key binds its value to itself.
     *
     * @return array<string, array{0: string|null, 1: 'transient'|'singleton'}>
     */
    private function declarations(mixed $bindings, mixed $singletons): array
    {
        $declarations = [];

        foreach (['transient' => $bindings, 'singleton' => $singletons] as $lifetime => $entries) {
            foreach (is_array($entries) ? $entries : [] as $key => $value) {
                $abstract = is_int($key) ? $value : $key;

                if (is_string($abstract)) {
                    $declarations[$abstract] = [is_string($value) ? ltrim($value, '\\') : null, $lifetime];
                }
            }
        }

        return $declarations;
    }

    /**
     * The container keeps stored instances and scoped keys protected; they
     * are read, never modified.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function storedInstances(): array
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->app);
        $scoped = (new ReflectionProperty(Container::class, 'scopedInstances'))->getValue($this->app);

        return [is_array($instances) ? $instances : [], is_array($scoped) ? array_values(array_filter($scoped, 'is_string')) : []];
    }

    /**
     * @return array{0: string|null, 1: 'class'|'return_type'|null}
     */
    private function concreteOf(mixed $concrete): array
    {
        if (! $concrete instanceof Closure) {
            return [null, null];
        }

        $function = new ReflectionFunction($concrete);
        $captured = $function->getStaticVariables()['concrete'] ?? null;
        $scope = $function->getClosureScopeClass();

        if (is_string($captured) && $scope !== null && is_a($scope->getName(), Container::class, true)) {
            return [ltrim($captured, '\\'), 'class'];
        }

        return $this->returnTypeOf($concrete);
    }

    /**
     * A closure's declared non-builtin return type, without calling it.
     *
     * @return array{0: string|null, 1: 'return_type'|null}
     */
    private function returnTypeOf(Closure $closure): array
    {
        $returnType = (new ReflectionFunction($closure))->getReturnType();

        if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin() && ! in_array(strtolower($returnType->getName()), ['self', 'static'], true)) {
            return [ltrim($returnType->getName(), '\\'), 'return_type'];
        }

        return [null, null];
    }

    private function isApplicationBinding(string $abstract, ?string $concrete): bool
    {
        return $this->isApplicationClass($abstract) || ($concrete !== null && $this->isApplicationClass($concrete));
    }

    private function isApplicationClass(string $class): bool
    {
        return str_starts_with(ltrim($class, '\\'), $this->app->getNamespace());
    }

    private function sourceOf(?string $concrete): ?SourceLocation
    {
        if ($concrete === null || ! (class_exists($concrete) || interface_exists($concrete))) {
            return null;
        }

        return (new SourceLocator)->forClass(new ReflectionClass($concrete));
    }
}
