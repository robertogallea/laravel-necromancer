<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Closure;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\ArtifactId;
use LaravelNecromancer\Manifest\SourceLocation;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\AnnotationMerger;
use LaravelNecromancer\Metadata\ArtifactAnnotations;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use LaravelNecromancer\Metadata\RouteAnnotationResolution;
use LaravelNecromancer\Metadata\RouteAnnotationResolver;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

final class RouteCollector
{
    /** @var list<string> */
    private array $diagnostics = [];

    public function __construct(private Router $router) {}

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        $this->diagnostics = [];
        $artifacts = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $artifacts[] = $this->collectRoute($route);
        }

        return $artifacts;
    }

    /** @return list<string> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    private function collectRoute(Route $route): StructuralArtifact
    {
        [$controller, $action] = $this->controllerAction($route);
        $metadata = $this->routeMetadata($route);
        $resolvedAnnotations = $this->resolvedAnnotations($metadata);
        $controllerAnnotations = $this->controllerAnnotations($controller, $action);

        // Native route metadata is the most specific declaration source: it wins
        // over a controller-derived default/refinement, but disagreement is
        // reported so an author knows the controller-level intent was ignored.
        // The conflict is labelled with the route's canonical Artifact ID — the
        // same identity ArtifactId assigns at serialization time — so warnings
        // for two different routes never collapse into one via array_unique().
        [$annotations, $conflictDiagnostics] = (new AnnotationMerger)->merge(
            $controllerAnnotations,
            $resolvedAnnotations->annotations,
            warnOnConflict: true,
            artifactLabel: (new ArtifactId)->for('routes', ['method' => $this->methodString($route), 'uri' => $route->uri()]),
            baseSourceLabel: 'the controller annotation',
            moreSpecificSourceLabel: 'route metadata',
        );

        $this->diagnostics = [...$this->diagnostics, ...$resolvedAnnotations->diagnostics, ...$conflictDiagnostics];

        return StructuralArtifact::route(
            name: $route->getName(),
            method: $this->methodString($route),
            uri: $route->uri(),
            middleware: $this->middleware($route),
            controller: $controller,
            action: $action,
            source: $this->source($route, $controller, $action),
            parameters: $this->parameters($route),
            authorization: $this->authorization($controller, $action),
            metadata: $metadata,
            annotations: $annotations,
        );
    }

    /**
     * The controller class annotation is an inherited default; the action method
     * annotation is a more specific declaration that silently refines it.
     */
    private function controllerAnnotations(?string $controller, ?string $action): ArtifactAnnotations
    {
        if ($controller === null || ! class_exists($controller)) {
            return new ArtifactAnnotations;
        }

        try {
            $classReflection = new ReflectionClass($controller);
            $methodReflection = ($action !== null && $classReflection->hasMethod($action))
                ? $classReflection->getMethod($action)
                : null;
        } catch (ReflectionException) {
            return new ArtifactAnnotations;
        }

        $resolver = new ClassAnnotationResolver;
        $classAnnotations = $resolver->resolve(AttributeReader::first($classReflection, Necromancer::class), $controller);
        $actionAnnotations = $methodReflection !== null
            ? $resolver->resolve(AttributeReader::first($methodReflection, Necromancer::class), "{$controller}::{$action}")
            : new ArtifactAnnotations;

        [$merged] = (new AnnotationMerger)->merge($classAnnotations, $actionAnnotations);

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function routeMetadata(Route $route): array
    {
        // Route::metadata()/getMetadata() only exists from Laravel 13.17+; this package
        // supports "^13.0", so the method may genuinely be absent at runtime even though
        // it's always present on whatever Laravel version PHPStan analyses against.
        if (! method_exists($route, 'getMetadata')) {
            return [];
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $route->getMetadata();

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $rawMetadata
     */
    private function resolvedAnnotations(array $rawMetadata): RouteAnnotationResolution
    {
        $namespace = (string) config('necromancer.route_metadata.namespace', 'necromancer');

        return (new RouteAnnotationResolver($namespace))->resolve($rawMetadata);
    }

    private function methodString(Route $route): string
    {
        $hasGet = in_array('GET', $route->methods(), true);

        $methods = array_values(array_filter(
            $route->methods(),
            static fn (string $method): bool => ! ($method === 'HEAD' && $hasGet),
        ));

        return implode('|', $methods);
    }

    /**
     * @return list<string>
     */
    private function middleware(Route $route): array
    {
        return array_values(array_map(
            fn (mixed $middleware): string => $this->middlewareName($middleware),
            $route->gatherMiddleware(),
        ));
    }

    private function middlewareName(mixed $middleware): string
    {
        if (is_string($middleware)) {
            return $middleware;
        }

        if ($middleware instanceof Closure) {
            return 'Closure';
        }

        if (is_object($middleware)) {
            return $middleware::class;
        }

        if (is_array($middleware)) {
            return implode('@', array_map(
                fn (mixed $part): string => $this->middlewareName($part),
                $middleware,
            ));
        }

        return (string) $middleware;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function controllerAction(Route $route): array
    {
        $controller = $route->getControllerClass();

        if (is_string($controller) && $controller !== '') {
            $uses = $route->getAction('uses');

            if (is_string($uses) && str_contains($uses, '@')) {
                $method = substr(strrchr($uses, '@') ?: '', 1);

                if ($method !== '') {
                    return [$controller, $method];
                }
            }

            return [$controller, $route->getActionMethod()];
        }

        if ($route->getAction('uses') instanceof Closure) {
            return [null, 'Closure'];
        }

        return [null, null];
    }

    private function source(Route $route, ?string $controller, ?string $action): ?SourceLocation
    {
        if (is_string($controller) && $controller !== '' && is_string($action) && $action !== '' && method_exists($controller, $action)) {
            try {
                return $this->sourceFromReflection(new ReflectionMethod($controller, $action));
            } catch (ReflectionException) {
                return null;
            }
        }

        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            return $this->sourceFromReflection(new ReflectionFunction($uses));
        }

        return null;
    }

    private function sourceFromReflection(ReflectionFunction|ReflectionMethod $reflection): ?SourceLocation
    {
        return (new SourceLocator)->forFunction($reflection);
    }

    /**
     * @return list<array{name: string, optional: bool, constraint: string|null}>
     */
    private function parameters(Route $route): array
    {
        $wheres = $route->wheres;
        $uri = $route->uri();
        $params = [];

        foreach ($route->parameterNames() as $name) {
            $params[] = [
                'name' => $name,
                'optional' => str_contains($uri, "{{$name}?}"),
                'constraint' => $wheres[$name] ?? null,
            ];
        }

        return $params;
    }

    /**
     * @return list<array{ability: string, models: list<string>}>
     */
    private function authorization(?string $controller, ?string $action): array
    {
        if ($controller === null || $action === null) {
            return [];
        }

        try {
            $classReflection = new ReflectionClass($controller);
            $methodReflection = $classReflection->hasMethod($action)
                ? $classReflection->getMethod($action)
                : null;
        } catch (ReflectionException) {
            return [];
        }

        $entries = [];

        foreach (AttributeReader::all($classReflection, Authorize::class) as $instance) {
            if ($methodReflection === null || ! $this->excludedByOptions($action, $instance)) {
                $entries[] = $this->authorizeEntry($instance);
            }
        }

        if ($methodReflection !== null) {
            foreach (AttributeReader::all($methodReflection, Authorize::class) as $instance) {
                $entries[] = $this->authorizeEntry($instance);
            }
        }

        return $entries;
    }

    /** @return array{ability: string, models: list<string>} */
    private function authorizeEntry(Authorize $attr): array
    {
        if (! is_string($attr->middleware)) {
            return ['ability' => 'Closure', 'models' => []];
        }

        $middleware = $attr->middleware;
        $colonPos = strpos($middleware, ':');
        $params = $colonPos !== false ? explode(',', substr($middleware, $colonPos + 1)) : [];
        $ability = $params[0] ?? $middleware;
        $models = array_values(array_filter(array_slice($params, 1)));

        return ['ability' => $ability, 'models' => $models];
    }

    private function excludedByOptions(string $method, Authorize $attr): bool
    {
        if (! empty($attr->only) && ! in_array($method, (array) $attr->only, true)) {
            return true;
        }

        if (! empty($attr->except) && in_array($method, (array) $attr->except, true)) {
            return true;
        }

        return false;
    }
}
