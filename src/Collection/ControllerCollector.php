<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Attributes\Controllers\Middleware as MiddlewareAttribute;
use Illuminate\Routing\Attributes\Controllers\WithoutMiddleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Manifest\ArtifactId;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

final readonly class ControllerCollector
{
    /**
     * @param  list<array{path: string, namespace: string}>|null  $roots
     * @param  string|null  $namespace  only route-targeted controllers under this namespace are collected
     */
    public function __construct(
        private Application $app,
        private Router $router,
        private ?array $roots = null,
        private ?string $namespace = null,
        private RouteNoiseFilter $routeNoiseFilter = new RouteNoiseFilter,
    ) {}

    /**
     * Return a new instance that ignores routes the given filter excludes from the manifest.
     */
    public function withRouteNoiseFilter(RouteNoiseFilter $routeNoiseFilter): self
    {
        return new self(
            app: $this->app,
            router: $this->router,
            roots: $this->roots,
            namespace: $this->namespace,
            routeNoiseFilter: $routeNoiseFilter,
        );
    }

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        $routeTargets = $this->routeTargets();
        $classes = array_values(array_unique([
            ...(new ClassDiscovery($this->discoveryRoots()))->classes(),
            ...array_filter(array_keys($routeTargets), fn (string $class): bool => str_starts_with($class, $this->appNamespace())),
        ]));
        sort($classes);

        $artifacts = [];

        foreach ($classes as $class) {
            $artifact = $this->collectClass($class, $routeTargets[$class] ?? []);

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
            'path' => $this->app->basePath('app/Http/Controllers'),
            'namespace' => rtrim($this->app->getNamespace(), '\\').'\\Http\\Controllers\\',
        ]];
    }

    private function appNamespace(): string
    {
        return rtrim($this->namespace ?? $this->app->getNamespace(), '\\').'\\';
    }

    /**
     * Controller class → action method → Artifact IDs of the manifest routes targeting it.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function routeTargets(): array
    {
        $targets = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $controller = $route->getControllerClass();

            if (! is_string($controller) || $controller === '' || ! $this->routeNoiseFilter->allows($this->routeArtifact($route))) {
                continue;
            }

            $targets[ltrim($controller, '\\')][$route->getActionMethod()][] = (new ArtifactId)->for('routes', [
                'method' => implode('|', $route->methods()),
                'uri' => $route->uri(),
            ]);
        }

        return $targets;
    }

    private function routeArtifact(Route $route): StructuralArtifact
    {
        return StructuralArtifact::route(
            name: $route->getName(),
            method: implode('|', $route->methods()),
            uri: $route->uri(),
        );
    }

    /**
     * Only the class-level #[Necromancer] annotates the controller artifact;
     * method-level attributes keep refining the annotations of their routes.
     *
     * @param  array<string, list<string>>  $routeTargets  action method → route Artifact IDs
     */
    private function collectClass(string $class, array $routeTargets): ?StructuralArtifact
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->isEnum()) {
            return null;
        }

        $actions = $this->actions($reflection, $routeTargets);

        if ($actions === []) {
            return null;
        }

        return StructuralArtifact::controller(
            class: $class,
            actions: $actions,
            source: (new SourceLocator)->forClass($reflection),
            annotations: (new ClassAnnotationResolver)->resolve(AttributeReader::first($reflection, Necromancer::class), $reflection->getName()),
        );
    }

    /**
     * @param  array<string, list<string>>  $routeTargets  action method → route Artifact IDs
     * @return list<array{name: string, parameters: list<array{name: string, type: string|null}>, return_type: string|null, middleware: list<string>, routes: list<string>}>
     */
    private function actions(ReflectionClass $reflection, array $routeTargets): array
    {
        $actions = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $this->isControllerAction($method, $reflection, $routeTargets)) {
                continue;
            }

            $routes = $routeTargets[$method->getName()] ?? [];
            sort($routes, SORT_STRING);

            $actions[] = [
                'name' => $method->getName(),
                'parameters' => array_map(
                    fn (ReflectionParameter $parameter): array => [
                        'name' => $parameter->getName(),
                        'type' => $parameter->getType()?->__toString(),
                    ],
                    $method->getParameters(),
                ),
                'return_type' => $method->getReturnType()?->__toString(),
                'middleware' => $this->middleware($reflection, $method->getName()),
                'routes' => $routes,
            ];
        }

        usort($actions, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $actions;
    }

    /**
     * Public instance methods written in the controller itself are Controller
     * Actions; one inherited from a parent or pulled in from a trait (reflection
     * reports it as declared on the class, but in the trait's file) only counts
     * when a route targets it, so every route lands on a listed action.
     *
     * @param  array<string, list<string>>  $routeTargets
     */
    private function isControllerAction(ReflectionMethod $method, ReflectionClass $reflection, array $routeTargets): bool
    {
        if ($method->isStatic()) {
            return false;
        }

        $writtenInController = $method->getDeclaringClass()->getName() === $reflection->getName()
            && $method->getFileName() === $reflection->getFileName();

        if (! $writtenInController) {
            return array_key_exists($method->getName(), $routeTargets);
        }

        return $method->getName() === '__invoke' || ! str_starts_with($method->getName(), '__');
    }

    /**
     * The middleware a controller declares for one action, resolved the way
     * Laravel's Route::controllerMiddleware() does but without instantiating
     * the controller: static HasMiddleware::middleware() entries, then
     * #[Middleware] attributes (parent classes first, then the method), minus
     * #[WithoutMiddleware] — each honoring only/except. Middleware registered
     * through $this->middleware() in a constructor is not visible here.
     *
     * @return list<string>
     */
    private function middleware(ReflectionClass $reflection, string $method): array
    {
        $middleware = [
            ...$this->staticMiddleware($reflection, $method),
            ...$this->attributeMiddleware($reflection, $method, MiddlewareAttribute::class),
        ];
        $excluded = $this->attributeMiddleware($reflection, $method, WithoutMiddleware::class);

        return array_values(array_filter(
            $middleware,
            fn (string $name): bool => ! in_array($name, $excluded, true),
        ));
    }

    /**
     * @return list<string>
     */
    private function staticMiddleware(ReflectionClass $reflection, string $method): array
    {
        if (! $reflection->implementsInterface(HasMiddleware::class)) {
            return [];
        }

        try {
            $declared = $reflection->getMethod('middleware')->invoke(null);
        } catch (Throwable) {
            return [];
        }

        $names = [];

        foreach ((array) $declared as $entry) {
            $entry = $entry instanceof Middleware ? $entry : new Middleware($entry);

            if (! $this->excludedByOptions($method, $entry->only, $entry->except)) {
                $names = [...$names, ...$this->middlewareNames($entry->middleware)];
            }
        }

        return $names;
    }

    /**
     * @param  class-string<MiddlewareAttribute|WithoutMiddleware>  $attribute
     * @return list<string>
     */
    private function attributeMiddleware(ReflectionClass $reflection, string $method, string $attribute): array
    {
        $instances = [];

        for ($current = $reflection; $current instanceof ReflectionClass; $current = $current->getParentClass() ?: null) {
            $instances = [...AttributeReader::all($current, $attribute), ...$instances];
        }

        if ($reflection->hasMethod($method)) {
            $instances = [...$instances, ...AttributeReader::all($reflection->getMethod($method), $attribute)];
        }

        $names = [];

        foreach ($instances as $instance) {
            if (! $this->excludedByOptions($method, $instance->only, $instance->except)) {
                $names = [...$names, ...$this->middlewareNames($instance->middleware)];
            }
        }

        return $names;
    }

    /**
     * @param  array<string>|null  $only
     * @param  array<string>|null  $except
     */
    private function excludedByOptions(string $method, ?array $only, ?array $except): bool
    {
        return ($only !== null && ! in_array($method, $only, true))
            || ($except !== null && $except !== [] && in_array($method, $except, true));
    }

    /**
     * @return list<string>
     */
    private function middlewareNames(mixed $middleware): array
    {
        if (is_array($middleware)) {
            $names = [];

            foreach ($middleware as $entry) {
                $names = [...$names, ...$this->middlewareNames($entry)];
            }

            return $names;
        }

        if ($middleware instanceof Closure) {
            return ['Closure'];
        }

        return [is_object($middleware) ? $middleware::class : (string) $middleware];
    }
}
