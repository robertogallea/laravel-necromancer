<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ArtifactAnnotations;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use ReflectionClass;
use Throwable;

final readonly class MiddlewareCollector
{
    public function __construct(private Application $app) {}

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        $artifacts = [];
        $annotationsByClass = [];

        $router = $this->app->make(Router::class);

        // 1. Global middleware (scope = "global") — via Http\Kernel reflection
        foreach ($this->globalMiddleware() as $class) {
            if (! $this->isAppMiddleware($class)) {
                continue;
            }

            $artifacts[] = StructuralArtifact::middleware(
                alias: class_basename($class),
                class: $class,
                scope: 'global',
                group: null,
                source: $this->sourceFor($class),
                annotations: $this->annotationsFor($class, $annotationsByClass),
            );
        }

        // 2. Middleware groups — web, api, etc. (scope = "group")
        foreach ($router->getMiddlewareGroups() as $groupName => $stack) {
            foreach ($stack as $entry) {
                if (! is_string($entry) || ! $this->isFqcn($entry)) {
                    continue;
                }

                if (! $this->isAppMiddleware($entry)) {
                    continue;
                }

                $artifacts[] = StructuralArtifact::middleware(
                    alias: class_basename($entry),
                    class: $entry,
                    scope: 'group',
                    group: $groupName,
                    source: $this->sourceFor($entry),
                    annotations: $this->annotationsFor($entry, $annotationsByClass),
                );
            }
        }

        // 3. Named aliases (scope = "alias")
        foreach ($router->getMiddleware() as $alias => $class) {
            if (! is_string($class)) {
                continue;
            }

            // Strip parameters from class (e.g. "throttle:60,1" → "throttle")
            $classString = explode(':', $class)[0];

            if (! $this->isAppMiddleware($classString)) {
                continue;
            }

            $artifacts[] = StructuralArtifact::middleware(
                alias: $alias,
                class: $classString,
                scope: 'alias',
                group: null,
                source: $this->sourceFor($classString),
                annotations: $this->annotationsFor($classString, $annotationsByClass),
            );
        }

        return $artifacts;
    }

    /**
     * A middleware class annotation applies to every collected registration of that
     * class, so the attribute is only read once per class and reused across scopes.
     *
     * @param  array<string, ArtifactAnnotations>  $cache
     */
    private function annotationsFor(string $class, array &$cache): ArtifactAnnotations
    {
        if (! isset($cache[$class])) {
            $cache[$class] = (new ClassAnnotationResolver)->resolve(
                AttributeReader::first(new ReflectionClass($class), Necromancer::class),
            );
        }

        return $cache[$class];
    }

    /**
     * @return list<string>
     */
    private function globalMiddleware(): array
    {
        try {
            $kernel = $this->app->make(Kernel::class);
            $reflection = new ReflectionClass($kernel);

            if ($reflection->hasMethod('getMiddleware')) {
                $result = $reflection->getMethod('getMiddleware')->invoke($kernel);

                return is_array($result) ? array_values(array_filter($result, 'is_string')) : [];
            }
        } catch (Throwable) {
            // Kernel not bound or reflection failed — skip global middleware
        }

        return [];
    }

    private function isAppMiddleware(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        // Skip framework-internal middleware
        if (str_starts_with($class, 'Illuminate\\')) {
            return false;
        }

        return true;
    }

    private function isFqcn(string $value): bool
    {
        return str_contains($value, '\\') || class_exists($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sourceFor(string $class): ?array
    {
        try {
            if (! class_exists($class)) {
                return null;
            }

            $reflection = new ReflectionClass($class);

            return (new SourceLocator)->forClass($reflection)?->jsonSerialize();
        } catch (Throwable) {
            return null;
        }
    }
}
