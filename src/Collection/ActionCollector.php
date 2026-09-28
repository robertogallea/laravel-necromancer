<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

final readonly class ActionCollector
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
            'path' => $this->app->basePath('app/Actions'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Actions\\',
        ]];
    }

    private function collectClass(string $class): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->isEnum()) {
            return null;
        }

        $entrypoints = $this->entrypoints($reflection);

        if ($entrypoints === []) {
            return null;
        }

        return StructuralArtifact::action(
            class: $class,
            entrypoints: $entrypoints,
            source: (new SourceLocator)->forClass($reflection),
            annotations: (new ClassAnnotationResolver)->resolve(AttributeReader::first($reflection, Necromancer::class), $reflection->getName()),
        );
    }

    /**
     * @return list<array{name: string, parameters: list<array{name: string, type: string|null}>, return_type: string|null}>
     */
    private function entrypoints(ReflectionClass $reflection): array
    {
        $entrypoints = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $this->isEntrypoint($method, $reflection)) {
                continue;
            }

            $entrypoints[] = [
                'name' => $method->getName(),
                'parameters' => array_map(
                    fn (ReflectionParameter $parameter): array => [
                        'name' => $parameter->getName(),
                        'type' => $parameter->getType()?->__toString(),
                    ],
                    $method->getParameters(),
                ),
                'return_type' => $method->getReturnType()?->__toString(),
            ];
        }

        usort($entrypoints, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $entrypoints;
    }

    private function isEntrypoint(ReflectionMethod $method, ReflectionClass $reflection): bool
    {
        if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $reflection->getName()) {
            return false;
        }

        return $method->getName() === '__invoke' || ! str_starts_with($method->getName(), '__');
    }
}
