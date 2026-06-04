<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Closure;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LaravelNecromancer\Manifest\SourceLocation;
use LaravelNecromancer\Manifest\StructuralArtifact;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

final readonly class RouteCollector
{
    public function __construct(private Router $router) {}

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        $artifacts = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $artifacts[] = $this->collectRoute($route);
        }

        return $artifacts;
    }

    private function collectRoute(Route $route): StructuralArtifact
    {
        [$controller, $action] = $this->controllerAction($route);

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
        );
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
