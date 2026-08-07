<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\ImplicitRule;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use ReflectionClass;

final readonly class RuleCollector
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
            'path' => $this->app->basePath('app/Rules'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Rules\\',
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

        $implementsValidationRule = $reflection->implementsInterface(ValidationRule::class);
        $implementsLegacyRule = $reflection->implementsInterface(Rule::class);

        if (! $implementsValidationRule && ! $implementsLegacyRule) {
            return null;
        }

        $implicit = $reflection->implementsInterface(ImplicitRule::class);
        $description = $this->extractDescription($reflection);

        return StructuralArtifact::validationRule(
            class: $class,
            implicit: $implicit,
            description: $description,
            source: (new SourceLocator)->forClass($reflection),
            annotations: (new ClassAnnotationResolver)->resolve(AttributeReader::first($reflection, Necromancer::class)),
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function extractDescription(ReflectionClass $reflection): ?string
    {
        $docblock = $reflection->getDocComment();

        if ($docblock === false) {
            return null;
        }

        $lines = explode("\n", $docblock);

        foreach ($lines as $line) {
            $stripped = trim(ltrim($line, " \t/*"));
            // Strip trailing */ closer that appears on single-line docblocks.
            if (str_ends_with($stripped, '*/')) {
                $stripped = trim(substr($stripped, 0, -2));
            }

            if ($stripped !== '' && $stripped !== '*') {
                return $stripped ?: null;
            }
        }

        return null;
    }
}
