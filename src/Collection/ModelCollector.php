<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use LaravelNecromancer\Manifest\StructuralArtifact;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final readonly class ModelCollector
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

        return [[
            'path' => $this->app->basePath('app'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\',
        ]];
    }

    private function collectClass(string $class): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            return null;
        }

        try {
            /** @var Model $model */
            $model = $reflection->newInstance();
        } catch (Throwable) {
            return null;
        }

        return StructuralArtifact::model(
            class: $class,
            table: $model->getTable(),
            fillable: $model->getFillable(),
            casts: $this->casts($model),
            relationships: $this->relationships($reflection, $model),
            hidden: $model->getHidden(),
            appends: $model->getAppends(),
            softDeletes: in_array(SoftDeletes::class, class_uses_recursive($class), strict: true),
            scopes: $this->localScopes($reflection),
            guarded: $model->getGuarded(),
            source: (new SourceLocator)->forClass($reflection),
            observers: $this->observers($reflection),
            globalScopes: $this->globalScopes($reflection),
            policy: $this->attributeClass($reflection, UsePolicy::class, 'class'),
            factory: $this->attributeClass($reflection, UseFactory::class, 'factoryClass'),
            customBuilder: $this->attributeClass($reflection, UseEloquentBuilder::class, 'builderClass'),
        );
    }

    /**
     * @return array<string, string>
     */
    private function casts(Model $model): array
    {
        $casts = [];

        foreach ($model->getCasts() as $attribute => $cast) {
            $casts[(string) $attribute] = $this->castDefinition($cast);
        }

        ksort($casts);

        return $casts;
    }

    private function castDefinition(mixed $cast): string
    {
        if (is_string($cast)) {
            return $cast;
        }

        if (is_object($cast)) {
            return $cast::class;
        }

        if (is_array($cast)) {
            try {
                return json_encode($cast, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return 'array';
            }
        }

        return (string) $cast;
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $reflection
     * @return list<string>
     */
    private function localScopes(ReflectionClass $reflection): array
    {
        $scopes = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $name = $method->getName();

            // Convention-based scopes use the public scopeXxx prefix
            if ($method->isPublic() && preg_match('/^scope[A-Z]/', $name)) {
                $scopes[] = lcfirst(substr($name, 5));

                continue;
            }

            // Attribute-based scopes may be public or protected
            if (! empty($method->getAttributes(Scope::class))) {
                $scopes[] = $name;
            }
        }

        sort($scopes);

        return $scopes;
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $reflection
     * @return list<string>
     */
    private function observers(ReflectionClass $reflection): array
    {
        $observers = [];

        foreach (AttributeReader::all($reflection, ObservedBy::class) as $attr) {
            foreach ((array) $attr->classes as $class) {
                $observers[] = $class;
            }
        }

        return $observers;
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $reflection
     * @return list<string>
     */
    private function globalScopes(ReflectionClass $reflection): array
    {
        $scopes = [];

        foreach (AttributeReader::all($reflection, ScopedBy::class) as $attr) {
            foreach ((array) $attr->classes as $class) {
                $scopes[] = $class;
            }
        }

        return $scopes;
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $reflection
     * @param  class-string  $attributeClass
     */
    private function attributeClass(ReflectionClass $reflection, string $attributeClass, string $property): ?string
    {
        $attr = AttributeReader::first($reflection, $attributeClass);

        return $attr !== null ? (string) $attr->{$property} : null;
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $reflection
     * @return list<array{type: string, related: string|null, method: string}>
     */
    private function relationships(ReflectionClass $reflection, Model $model): array
    {
        $relationships = [];
        $modelFile = $reflection->getFileName();

        // Swap the connection resolver to an in-memory SQLite instance so that
        // invoking relationship methods never blocks on an unavailable database.
        $originalResolver = Model::getConnectionResolver();
        Model::setConnectionResolver(new InMemoryConnectionResolver);

        try {
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic()
                    || $method->getDeclaringClass()->getName() !== $reflection->getName()
                    || $method->getFileName() !== $modelFile
                    || $method->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                try {
                    $relation = Relation::noConstraints(fn (): mixed => $method->invoke($model));
                } catch (Throwable) {
                    continue;
                }

                if (! $relation instanceof Relation) {
                    continue;
                }

                $relationships[] = [
                    'type' => lcfirst(class_basename($relation)),
                    'related' => $relation->getRelated()::class,
                    'method' => $method->getName(),
                ];
            }
        } finally {
            Model::setConnectionResolver($originalResolver);
        }

        usort(
            $relationships,
            static fn (array $first, array $second): int => $first['method'] <=> $second['method'],
        );

        return $relationships;
    }
}
