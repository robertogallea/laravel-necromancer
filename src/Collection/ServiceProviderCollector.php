<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use ReflectionClass;

final readonly class ServiceProviderCollector
{
    public function __construct(private Application $app) {}

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        $providersFile = $this->app->basePath('bootstrap/providers.php');

        if (! file_exists($providersFile)) {
            return [];
        }

        $providers = require $providersFile;

        if (! is_array($providers)) {
            return [];
        }

        $appNamespace = rtrim($this->app->getNamespace(), '\\');

        $appProviders = array_filter(
            $providers,
            fn (mixed $class): bool => is_string($class) && str_starts_with($class, $appNamespace),
        );

        $artifacts = [];

        foreach ($appProviders as $providerClass) {
            $artifact = $this->collectProvider($providerClass);

            if ($artifact instanceof StructuralArtifact) {
                $artifacts[] = $artifact;
            }
        }

        return $artifacts;
    }

    private function collectProvider(string $class): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return null;
        }

        $deferred = $reflection->implementsInterface(DeferrableProvider::class);
        $source = (new SourceLocator)->forClass($reflection);

        return StructuralArtifact::serviceProvider(
            class: $class,
            deferred: $deferred,
            bindings: [],
            singletons: [],
            source: $source,
            annotations: (new ClassAnnotationResolver)->resolve(AttributeReader::first($reflection, Necromancer::class)),
        );
    }
}
