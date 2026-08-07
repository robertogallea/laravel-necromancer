<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final readonly class PolicyCollector
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
            'path' => $this->app->basePath('app/Policies'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Policies\\',
        ]];
    }

    private function collectClass(string $class): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return null;
        }

        return StructuralArtifact::policy(
            class: $class,
            model: $this->guardedModel($reflection),
            methods: $this->policyMethods($reflection),
            source: (new SourceLocator)->forClass($reflection),
            annotations: (new ClassAnnotationResolver)->resolve(AttributeReader::first($reflection, Necromancer::class), $reflection->getName()),
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function guardedModel(ReflectionClass $reflection): ?string
    {
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $params = $method->getParameters();

            if (count($params) < 2) {
                continue;
            }

            $type = $params[1]->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return list<string>
     */
    private function policyMethods(ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()
                || $method->isConstructor()
                || $method->getDeclaringClass()->getName() !== $reflection->getName()
                || str_starts_with($method->getName(), '__')) {
                continue;
            }

            $methods[] = $method->getName();
        }

        sort($methods);

        return $methods;
    }
}
