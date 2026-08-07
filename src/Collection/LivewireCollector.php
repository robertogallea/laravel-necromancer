<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use Livewire\Attributes\On;
use Livewire\Component;
use ReflectionClass;
use ReflectionNamedType;

final readonly class LivewireCollector
{
    /**
     * @param  list<array{path: string, namespace: string}>|null  $roots
     */
    public function __construct(
        private Application $app,
        private ?array $roots = null,
    ) {}

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        if (! class_exists(Component::class)) {
            return [];
        }

        $artifacts = [];

        foreach ((new ClassDiscovery($this->discoveryRoots()))->classes() as $class) {
            $artifact = $this->collectClass($class);

            if ($artifact instanceof StructuralArtifact) {
                $artifacts[] = $artifact;
            }
        }

        return $artifacts;
    }

    /**
     * @return list<array{path: string, namespace: string}>
     */
    private function discoveryRoots(): array
    {
        if (is_array($this->roots)) {
            return $this->roots;
        }

        return [[
            'path' => $this->app->basePath('app/Livewire'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Livewire\\',
        ]];
    }

    private function collectClass(string $class): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Component::class)) {
            return null;
        }

        $properties = $this->collectProperties($reflection);
        $actions = $this->collectActions($reflection);
        $listens = $this->collectListens($reflection);
        $view = $this->inferViewName($class);

        return StructuralArtifact::livewireComponent(
            class: $class,
            view: $view,
            properties: $properties,
            actions: $actions,
            listens: $listens,
            source: (new SourceLocator)->forClass($reflection),
            annotations: (new ClassAnnotationResolver)->resolve(AttributeReader::first($reflection, Necromancer::class)),
        );
    }

    /**
     * @param  ReflectionClass<Component>  $reflection
     * @return list<array{name: string, type: string|null}>
     */
    private function collectProperties(ReflectionClass $reflection): array
    {
        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $type = $property->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            $properties[] = [
                'name' => $property->getName(),
                'type' => $typeName,
            ];
        }

        return $properties;
    }

    /**
     * @param  ReflectionClass<Component>  $reflection
     * @return list<string>
     */
    private function collectActions(ReflectionClass $reflection): array
    {
        $isLifecycleHook = fn (string $name): bool => in_array($name, ['mount', 'render', 'boot', 'booted', '__construct'], true)
            || str_starts_with($name, 'updating')
            || str_starts_with($name, 'updated')
            || str_starts_with($name, 'hydrate')
            || str_starts_with($name, 'dehydrate');

        $actions = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isStatic()) {
                continue;
            }

            if ($method->isAbstract()) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            if (str_starts_with($name, '__')) {
                continue;
            }

            if ($isLifecycleHook($name)) {
                continue;
            }

            $actions[] = $name;
        }

        return $actions;
    }

    /**
     * @param  ReflectionClass<Component>  $reflection
     * @return list<string>
     */
    private function collectListens(ReflectionClass $reflection): array
    {
        if (! class_exists(On::class)) {
            return [];
        }

        $listens = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(On::class) as $attr) {
                $args = $attr->getArguments();

                if (isset($args[0])) {
                    foreach (Arr::wrap($args[0]) as $event) {
                        if (is_string($event)) {
                            $listens[] = $event;
                        }
                    }
                }
            }
        }

        return $listens;
    }

    private function inferViewName(string $class): string
    {
        $namespace = $this->app->getNamespace().'Livewire\\';
        $shortName = str_replace($namespace, '', $class);

        return 'livewire.'.Str::of($shortName)
            ->replace('\\', '/')
            ->explode('/')
            ->map(fn (string $s) => Str::kebab($s))
            ->implode('.');
    }
}
