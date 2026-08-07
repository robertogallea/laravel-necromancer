<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use BackedEnum;
use Illuminate\Contracts\Foundation\Application;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use ReflectionEnum;

final readonly class EnumCollector
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
            'path' => $this->app->basePath('app/Enums'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Enums\\',
        ]];
    }

    private function collectClass(string $class): ?StructuralArtifact
    {
        if (! enum_exists($class)) {
            return null;
        }

        $reflection = new ReflectionEnum($class);

        $backingType = $reflection->isBacked()
            ? (string) $reflection->getBackingType()
            : null;

        $cases = array_map(
            fn ($case): array => [
                'name' => $case->getName(),
                'value' => $case->getValue() instanceof BackedEnum ? $case->getValue()->value : null,
            ],
            $reflection->getCases(),
        );

        return StructuralArtifact::enum(
            class: $class,
            backingType: $backingType,
            cases: $cases,
            source: (new SourceLocator)->forClass($reflection),
            annotations: (new ClassAnnotationResolver)->resolve(AttributeReader::first($reflection, Necromancer::class), $reflection->getName()),
        );
    }
}
