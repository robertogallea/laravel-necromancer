<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\Attributes\StopOnFirstFailure;
use Illuminate\Foundation\Http\FormRequest;
use LaravelNecromancer\Manifest\StructuralArtifact;
use ReflectionClass;
use ReflectionException;
use Throwable;

final readonly class FormRequestCollector
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

        foreach ($this->discoveredClasses() as $class) {
            $artifact = $this->collectClass($class);

            if ($artifact instanceof StructuralArtifact) {
                $artifacts[] = $artifact;
            }
        }

        return $artifacts;
    }

    /**
     * @return list<string>
     */
    private function discoveredClasses(): array
    {
        return (new ClassDiscovery($this->discoveryRoots()))->classes();
    }

    /**
     * @return list<array{path: string, namespace: string}>
     */
    private function discoveryRoots(): array
    {
        if (is_array($this->roots)) {
            return $this->roots;
        }

        $namespace = rtrim($this->app->getNamespace(), '\\').'\\';

        return [[
            'path' => $this->app->basePath('app/Http/Requests'),
            'namespace' => $namespace.'Http\\Requests\\',
        ]];
    }

    private function collectClass(string $class): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return null;
        }

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(FormRequest::class)) {
            return null;
        }

        return StructuralArtifact::formRequest(
            class: $class,
            rules: $this->extractRules($reflection),
            source: (new SourceLocator)->forClass($reflection),
            stopOnFirstFailure: AttributeReader::first($reflection, StopOnFirstFailure::class) !== null,
            errorBag: AttributeReader::first($reflection, ErrorBag::class)?->name,
        );
    }

    /**
     * @param  ReflectionClass<FormRequest>  $reflection
     * @return array<string, string>
     */
    private function extractRules(ReflectionClass $reflection): array
    {
        if (! $reflection->hasMethod('rules')) {
            return [];
        }

        try {
            $instance = $reflection->newInstanceWithoutConstructor();
            $raw = $instance->rules();
        } catch (Throwable) {
            return [];
        }

        $rules = [];

        foreach ($raw as $field => $rule) {
            $rules[(string) $field] = $this->ruleToString($rule);
        }

        return $rules;
    }

    private function ruleToString(mixed $rule): string
    {
        if (is_string($rule)) {
            return $rule;
        }

        if (is_array($rule)) {
            $parts = array_map(
                fn (mixed $r): string => is_object($r) ? $r::class : (string) $r,
                $rule,
            );

            return implode('|', $parts);
        }

        if (is_object($rule)) {
            return $rule::class;
        }

        return (string) $rule;
    }
}
