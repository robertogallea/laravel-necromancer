<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

use LaravelNecromancer\Collection\TestReferenceFactResolver;
use LaravelNecromancer\Manifest\ArtifactId;
use LaravelNecromancer\Okf\UriReference;

/**
 * Derives every Relationship a manifest implies, in memory. The single
 * source of the relationship vocabulary for every consumer (Artifact Graph,
 * Knowledge Bundle, ...).
 */
final class RelationshipResolver
{
    /**
     * @var list<string>
     */
    private const BUILTIN_TYPES = [
        'int', 'float', 'string', 'bool', 'array', 'iterable', 'callable', 'object', 'mixed',
        'null', 'false', 'true', 'void', 'never', 'self', 'static', 'parent',
    ];

    /**
     * Class name → Artifact ID for every class-backed artifact.
     *
     * @var array<string, string>
     */
    private array $classIndex = [];

    /**
     * Global Binding abstract → its Artifact ID, the fallback for a class no
     * collected artifact has (ADR 0025).
     *
     * @var array<string, string>
     */
    private array $bindingIndex = [];

    /**
     * Middleware registrations, for resolving a route's middleware names.
     *
     * @var list<array{id: string, class: string, scope: string, alias: string, group: string}>
     */
    private array $middleware = [];

    /**
     * Controller class → action name → that action's declared parameters.
     *
     * @var array<string, array<string, list<mixed>>>
     */
    private array $controllerActions = [];

    /**
     * Model class → the policy class governing it, from either end.
     *
     * @var array<string, string>
     */
    private array $policyByModel = [];

    /**
     * Gate ability → its Artifact ID.
     *
     * @var array<string, string>
     */
    private array $gates = [];

    /**
     * Route name → its Artifact ID.
     *
     * @var array<string, string>
     */
    private array $routeNames = [];

    /**
     * Relationships keyed by identity, in first-seen order.
     *
     * @var array<string, Relationship>
     */
    private array $relationships = [];

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<Relationship>
     */
    public function resolve(array $manifest): array
    {
        $artifacts = (array) ($manifest['artifacts'] ?? []);
        $this->classIndex = $this->buildClassIndex($artifacts);
        $this->bindingIndex = $this->buildBindingIndex($artifacts);
        $this->middleware = $this->buildMiddlewareIndex($artifacts);
        $this->controllerActions = $this->buildControllerActionIndex($artifacts);
        $this->policyByModel = $this->buildPolicyIndex($artifacts);
        $this->gates = $this->buildGateIndex($artifacts);
        $this->routeNames = $this->buildRouteNameIndex($artifacts);
        $this->relationships = [];

        foreach (ArtifactId::supportedTypes() as $type) {
            foreach ((array) ($artifacts[$type] ?? []) as $artifact) {
                if (! is_array($artifact) || ! is_string($artifact['id'] ?? null) || $artifact['id'] === '') {
                    continue;
                }

                match ($type) {
                    'routes' => $this->routeRelationships($artifact),
                    'models' => $this->modelRelationships($artifact),
                    'actions' => $this->actionRelationships($artifact),
                    'events' => $this->classListRelationships($artifact, 'listeners', RelationshipType::ListenedBy, Provenance::Runtime),
                    'listeners' => $this->pairRelationship($artifact, 'handles', RelationshipType::ListenedBy, Provenance::Runtime),
                    'policies' => $this->pairRelationship($artifact, 'model', RelationshipType::AuthorizedBy, Provenance::Reflection, ['heuristic' => true]),
                    'observers' => $this->pairRelationship($artifact, 'model', RelationshipType::ObservedBy, Provenance::Reflection),
                    'tests' => $this->testRelationships($artifact),
                    'bindings' => $this->bindingRelationships($artifact),
                    default => null,
                };

                $this->dispatchRelationships($artifact);
                $this->annotationRelationships($artifact);
            }
        }

        return array_values($this->relationships);
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function routeRelationships(array $route): void
    {
        $controller = $route['controller'] ?? null;

        if (is_string($controller) && $controller !== '') {
            $action = $route['action'] ?? null;

            $this->addToClass($route['id'], RelationshipType::HandledBy, $controller, Provenance::Runtime, 'controller', is_string($action) && $action !== '' ? ['action' => $action] : []);
        }

        $this->middlewareRelationships($route);
        $this->formRequestRelationships($route);
        $this->routeAuthorizationRelationships($route);
    }

    /**
     * Only parameters typed with a collected form request count — a class
     * Necromancer didn't collect can't be known to be a FormRequest.
     *
     * @param  array<string, mixed>  $route
     */
    private function formRequestRelationships(array $route): void
    {
        $controller = $route['controller'] ?? null;
        $action = $route['action'] ?? null;

        if (! is_string($controller) || ! is_string($action)) {
            return;
        }

        foreach ($this->controllerActions[$controller][$action] ?? [] as $parameter) {
            $class = is_array($parameter) ? ($parameter['type'] ?? null) : null;
            $to = is_string($class) ? ($this->classIndex[ltrim($class, '?')] ?? null) : null;

            if ($to !== null && str_starts_with($to, 'form_requests:')) {
                $this->add(new Relationship($route['id'], RelationshipType::ValidatesWith, $to, [Provenance::Reflection], true, [], [
                    new RelationshipEvidence($this->classIndex[$controller] ?? $controller, 'actions', ltrim((string) $class, '?')),
                ]));
            }
        }
    }

    /**
     * Each `#[Authorize]` entry targets the policy of every model it names.
     * When it names no model, or a model with no known policy, it also
     * falls back to a gate registered for the ability, and otherwise stays
     * unresolved under the ability name.
     *
     * @param  array<string, mixed>  $route
     */
    private function routeAuthorizationRelationships(array $route): void
    {
        foreach ((array) ($route['authorization'] ?? []) as $entry) {
            $ability = is_array($entry) ? ($entry['ability'] ?? null) : null;

            if (! is_string($ability) || $ability === '') {
                continue;
            }

            $models = array_values(array_filter((array) ($entry['models'] ?? []), 'is_string'));
            $metadata = ['ability' => $ability, 'models' => $models];
            $evidence = [new RelationshipEvidence($route['id'], 'authorization', $ability)];
            $needsFallback = $models === [];

            foreach ($models as $model) {
                $policy = $this->policyByModel[$model] ?? null;

                if ($policy === null) {
                    $needsFallback = true;

                    continue;
                }

                $to = $this->classIndex[$policy] ?? null;
                $this->add(new Relationship($route['id'], RelationshipType::AuthorizedBy, $to ?? $policy, [Provenance::Reflection], $to !== null, $metadata, $evidence));
            }

            if ($needsFallback) {
                $gate = $this->gates[$ability] ?? null;
                $this->add(new Relationship($route['id'], RelationshipType::AuthorizedBy, $gate ?? $ability, [Provenance::Reflection], $gate !== null, $metadata, $evidence));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function modelRelationships(array $model): void
    {
        foreach (array_values((array) ($model['relationships'] ?? [])) as $position => $relationship) {
            $method = is_array($relationship) ? ($relationship['method'] ?? null) : null;
            $related = is_array($relationship) ? ($relationship['related'] ?? null) : null;

            if (! is_string($method) || $method === '' || ! is_string($related) || $related === '') {
                continue;
            }

            $this->addToClass($model['id'], RelationshipType::RelatesTo, $related, Provenance::Runtime, 'relationships', [
                'method' => $method,
                'kind' => (string) ($relationship['type'] ?? ''),
            ], $position);
        }

        $policy = $model['policy'] ?? null;

        if (is_string($policy) && $policy !== '') {
            $this->addToClass($model['id'], RelationshipType::AuthorizedBy, $policy, Provenance::Reflection, 'policy');
        }

        $this->classListRelationships($model, 'observers', RelationshipType::ObservedBy, Provenance::Reflection);
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function classListRelationships(array $artifact, string $field, RelationshipType $type, Provenance $provenance): void
    {
        foreach (array_values((array) ($artifact[$field] ?? [])) as $position => $class) {
            if (is_string($class) && $class !== '') {
                $this->addToClass($artifact['id'], $type, $class, $provenance, $field, position: $position);
            }
        }
    }

    /**
     * Class types an action's entrypoints accept. Nullable, union, and
     * intersection types are split into their parts and built-in types are
     * dropped, since only classes can be other artifacts.
     *
     * @param  array<string, mixed>  $action
     */
    private function actionRelationships(array $action): void
    {
        $classes = [];

        foreach ((array) ($action['entrypoints'] ?? []) as $entrypoint) {
            foreach ((array) (is_array($entrypoint) ? ($entrypoint['parameters'] ?? []) : []) as $parameter) {
                $type = is_array($parameter) ? ($parameter['type'] ?? null) : null;

                if (! is_string($type)) {
                    continue;
                }

                foreach (preg_split('/[|&]/', str_replace(['?', '(', ')'], '', $type)) ?: [] as $part) {
                    if ($part !== '' && ! in_array(strtolower($part), self::BUILTIN_TYPES, true)) {
                        $classes[$part] = true;
                    }
                }
            }
        }

        foreach (array_keys($classes) as $position => $class) {
            $this->addToClass($action['id'], RelationshipType::OperatesOn, (string) $class, Provenance::Reflection, 'entrypoints', position: $position);
        }
    }

    /**
     * The far end of a pair whose canonical direction points at this
     * artifact (policy.model → model authorized_by policy, observer.model →
     * model observed_by observer): the relationship starts at the class the
     * field names, which stays raw when that class wasn't collected.
     *
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $metadata
     */
    private function pairRelationship(array $artifact, string $field, RelationshipType $type, Provenance $provenance, array $metadata = []): void
    {
        foreach (array_values((array) ($artifact[$field] ?? [])) as $position => $class) {
            if (! is_string($class) || $class === '') {
                continue;
            }

            $from = $this->classTarget($class);

            $this->add(new Relationship($from ?? $class, $type, $artifact['id'], [$provenance], $from !== null, $metadata, [
                new RelationshipEvidence($artifact['id'], $field, $class, $position),
            ]));
        }
    }

    /**
     * Subject relationships first, then one per class or route name the
     * test's source references (`match: reference`); a reference merged
     * into a subject relationship keeps the subject's match.
     *
     * @param  array<string, mixed>  $test
     */
    private function testRelationships(array $test): void
    {
        $this->testSubjectRelationships($test);

        foreach (array_values((array) ($test['references'] ?? [])) as $position => $reference) {
            $kind = is_array($reference) ? ($reference['kind'] ?? null) : null;
            $target = is_array($reference) ? ($reference['target'] ?? null) : null;

            if (! is_string($target) || $target === '' || ! in_array($kind, [TestReferenceFactResolver::CLASS_REFERENCE, TestReferenceFactResolver::ROUTE_REFERENCE], true)) {
                continue;
            }

            $from = $kind === TestReferenceFactResolver::CLASS_REFERENCE ? $this->classTarget($target) : ($this->routeNames[$target] ?? null);

            $this->add(new Relationship($from ?? $target, RelationshipType::TestedBy, $test['id'], [Provenance::Source], $from !== null, ['match' => TestMatch::Reference->value], [
                new RelationshipEvidence($test['id'], 'references', $target, $position),
            ]));
        }
    }

    /**
     * Same matching as TestSubjectMatcher: a subject naming a class exactly
     * covers that artifact; a subject naming a namespace covers every
     * collected artifact under it. A subject matching nothing collected
     * stays an unresolved relationship from the raw subject.
     *
     * @param  array<string, mixed>  $test
     */
    private function testSubjectRelationships(array $test): void
    {
        $subject = $test['subject'] ?? null;

        if (! is_string($subject) || $subject === '') {
            return;
        }

        $evidence = [new RelationshipEvidence($test['id'], 'subject', $subject)];
        $matched = false;

        foreach ($this->classIndex as $class => $id) {
            $match = match (true) {
                $class === $subject => TestMatch::Exact->value,
                str_starts_with($class, $subject.'\\') => TestMatch::Namespace->value,
                default => null,
            };

            if ($match !== null) {
                $matched = true;
                $this->add(new Relationship($id, RelationshipType::TestedBy, $test['id'], [Provenance::Source], true, ['match' => $match], $evidence));
            }
        }

        if (! $matched) {
            $binding = $this->bindingIndex[$subject] ?? null;
            $this->add(new Relationship($binding ?? $subject, RelationshipType::TestedBy, $test['id'], [Provenance::Source], $binding !== null, ['match' => TestMatch::Exact->value], $evidence));
        }
    }

    /**
     * One relationship per dispatched target, however many methods dispatch
     * it and through whichever APIs; `null` modes (events) are not listed.
     *
     * @param  array<string, mixed>  $artifact
     */
    private function dispatchRelationships(array $artifact): void
    {
        foreach (array_values((array) ($artifact['dispatches'] ?? [])) as $position => $dispatch) {
            $target = is_array($dispatch) ? ($dispatch['target'] ?? null) : null;
            $method = is_array($dispatch) ? ($dispatch['method'] ?? null) : null;

            if (! is_string($target) || $target === '' || ! is_string($method)) {
                continue;
            }

            $mode = $dispatch['mode'] ?? null;

            $this->addToClass($artifact['id'], RelationshipType::Dispatches, $target, Provenance::Source, 'dispatches', [
                'methods' => [$method],
                'modes' => is_string($mode) ? [$mode] : [],
            ], $position);
        }
    }

    /**
     * What the container provides for the abstract and, when declared, the
     * provider that registered it. A `return_type` or `attribute` concrete
     * is read from declarations; a `class` or `instance` one from the
     * container itself. The concrete resolves only to a collected artifact,
     * never through the binding fallback, so a binding never resolves to
     * itself or chains to another binding. A Contextual Binding is also
     * consumed_by its Consumer and takes_precedence_over the Global Binding
     * of its abstract (ADR 0026).
     *
     * @param  array<string, mixed>  $binding
     */
    private function bindingRelationships(array $binding): void
    {
        $concrete = $binding['concrete'] ?? null;

        if (is_string($concrete) && $concrete !== '') {
            $provenance = in_array($binding['concrete_source'] ?? null, ['return_type', 'attribute'], true) ? Provenance::Reflection : Provenance::Runtime;
            $to = $this->classIndex[$concrete] ?? null;

            $this->add(new Relationship($binding['id'], RelationshipType::ResolvedAs, $to ?? $concrete, [$provenance], $to !== null, [], [
                new RelationshipEvidence($binding['id'], 'concrete', $concrete),
            ]));
        }

        $provider = $binding['provider'] ?? null;

        if (is_string($provider) && $provider !== '') {
            $this->addToClass($binding['id'], RelationshipType::RegisteredBy, $provider, Provenance::Reflection, 'provider');
        }

        $consumer = $binding['consumer'] ?? null;
        $abstract = $binding['abstract'] ?? null;

        if (is_string($consumer) && $consumer !== '') {
            $this->addToClass($binding['id'], RelationshipType::ConsumedBy, $consumer, Provenance::Runtime, 'consumer');

            if (is_string($abstract) && $abstract !== '') {
                $global = $this->bindingIndex[$abstract] ?? null;

                $this->add(new Relationship($binding['id'], RelationshipType::TakesPrecedenceOver, $global ?? $abstract, [Provenance::Runtime], $global !== null, [], [
                    new RelationshipEvidence($binding['id'], 'abstract', $abstract),
                ]));
            }
        }
    }

    /**
     * Domain/Flow/ADR ends are synthesized concepts rather than collected
     * artifacts, but always exist, so these relationships are resolved.
     * Absolute-URI ADRs are external links, not ADR concepts, and skipped.
     *
     * @param  array<string, mixed>  $artifact
     */
    private function annotationRelationships(array $artifact): void
    {
        $annotations = is_array($artifact['annotations'] ?? null) ? $artifact['annotations'] : [];

        foreach (['domain' => RelationshipType::BelongsToDomain, 'flow' => RelationshipType::BelongsToFlow] as $field => $type) {
            $value = $annotations[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $this->addAnnotation($artifact['id'], $type, "{$field}:{$value}", $field, $value);
            }
        }

        foreach ((array) ($annotations['adrs'] ?? []) as $adr) {
            if (is_string($adr) && $adr !== '' && ! UriReference::isAbsolute($adr)) {
                $this->addAnnotation($artifact['id'], RelationshipType::ReferencesAdr, "adr:{$adr}", 'adrs', $adr);
            }
        }
    }

    private function addAnnotation(string $from, RelationshipType $type, string $to, string $field, string $value): void
    {
        $this->add(new Relationship($from, $type, $to, [Provenance::Annotation], true, [], [new RelationshipEvidence($from, $field, $value)]));
    }

    /**
     * A group name expands to one relationship per collected member; an
     * alias resolves to its alias registration; a class resolves to its
     * registration in one of the route's own groups when there is one (so a
     * middleware reached both directly and through a group stays a single
     * relationship), then its alias registration, then any registration.
     * Anything else (vendor middleware, a group with no collected member)
     * stays unresolved under its raw name.
     *
     * @param  array<string, mixed>  $route
     */
    private function middlewareRelationships(array $route): void
    {
        $entries = array_values(array_filter((array) ($route['middleware'] ?? []), fn (mixed $entry): bool => is_string($entry) && $entry !== ''));
        $routeGroups = [];

        foreach ($entries as $entry) {
            $members = array_filter($this->middleware, fn (array $registration): bool => $registration['scope'] === 'group' && $registration['group'] === $entry);

            if ($members !== []) {
                $routeGroups[] = $entry;
            }

            foreach ($members as $member) {
                $this->addMiddleware($route['id'], $member['id'], $entry, ['groups' => [$entry], 'direct' => false]);
            }
        }

        foreach ($entries as $entry) {
            if (in_array($entry, $routeGroups, true)) {
                continue;
            }

            $this->addMiddleware($route['id'], $this->directMiddlewareTarget(explode(':', $entry)[0], $routeGroups), $entry, ['groups' => [], 'direct' => true]);
        }
    }

    /**
     * @param  list<string>  $routeGroups
     */
    private function directMiddlewareTarget(string $name, array $routeGroups): ?string
    {
        foreach ($this->middleware as $registration) {
            if ($registration['scope'] === 'alias' && $registration['alias'] === $name) {
                return $registration['id'];
            }
        }

        $registrations = array_values(array_filter($this->middleware, fn (array $registration): bool => $registration['class'] === $name));

        foreach ($registrations as $registration) {
            if ($registration['scope'] === 'group' && in_array($registration['group'], $routeGroups, true)) {
                return $registration['id'];
            }
        }

        foreach ($registrations as $registration) {
            if ($registration['scope'] === 'alias') {
                return $registration['id'];
            }
        }

        return $registrations[0]['id'] ?? null;
    }

    /**
     * @param  array{groups: list<string>, direct: bool}  $metadata
     */
    private function addMiddleware(string $routeId, ?string $target, string $entry, array $metadata): void
    {
        $this->add(new Relationship(
            $routeId,
            RelationshipType::UsesMiddleware,
            $target ?? $entry,
            [Provenance::Runtime],
            $target !== null,
            $metadata,
            [new RelationshipEvidence($routeId, 'middleware', $entry)],
        ));
    }

    /**
     * Adds a relationship from an artifact to whatever artifact a class
     * name resolves to, or to the raw class when it isn't collected.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function addToClass(string $from, RelationshipType $type, string $class, Provenance $provenance, string $field, array $metadata = [], int $position = 0): void
    {
        $to = $this->classTarget($class);

        $this->add(new Relationship($from, $type, $to ?? $class, [$provenance], $to !== null, $metadata, [new RelationshipEvidence($from, $field, $class, $position)]));
    }

    /**
     * The artifact a class resolves to: a collected artifact always wins,
     * else the Global Binding of that abstract (ADR 0025), never a
     * Contextual Binding (ADR 0026).
     */
    private function classTarget(string $class): ?string
    {
        return $this->classIndex[$class] ?? $this->bindingIndex[$class] ?? null;
    }

    /**
     * Records a relationship, merging it into an existing one with the same
     * identity — (from, type, to, discriminator) — by unioning provenance,
     * evidence, middleware paths, and dispatching methods/modes. A heuristic
     * relationship stops being one as soon as any non-heuristic fact
     * supports it.
     */
    private function add(Relationship $relationship): void
    {
        $key = implode("\0", [$relationship->from, $relationship->type->value, $relationship->to, $this->discriminator($relationship)]);
        $existing = $this->relationships[$key] ?? null;

        if ($existing === null) {
            $this->relationships[$key] = $relationship;

            return;
        }

        $metadata = $existing->metadata;

        if (isset($metadata['groups'], $relationship->metadata['groups'])) {
            $metadata['groups'] = array_values(array_unique([...$metadata['groups'], ...$relationship->metadata['groups']]));
            $metadata['direct'] = $metadata['direct'] || $relationship->metadata['direct'];
        }

        if (isset($metadata['methods'], $relationship->metadata['methods'])) {
            $metadata['methods'] = array_values(array_unique([...$metadata['methods'], ...$relationship->metadata['methods']]));
            $metadata['modes'] = array_values(array_unique([...$metadata['modes'], ...$relationship->metadata['modes']]));
        }

        if (! isset($relationship->metadata['heuristic'])) {
            unset($metadata['heuristic']);
        }

        $this->relationships[$key] = new Relationship(
            $existing->from,
            $existing->type,
            $existing->to,
            $this->union($existing->provenance, $relationship->provenance),
            $existing->resolved,
            $metadata,
            $this->union($existing->evidence, $relationship->evidence),
        );
    }

    /**
     * Order-preserving union, comparing by value (enum cases, evidence objects).
     *
     * @template T
     *
     * @param  list<T>  $existing
     * @param  list<T>  $additional
     * @return list<T>
     */
    private function union(array $existing, array $additional): array
    {
        foreach ($additional as $item) {
            if (! in_array($item, $existing)) {
                $existing[] = $item;
            }
        }

        return $existing;
    }

    private function discriminator(Relationship $relationship): string
    {
        return match ($relationship->type) {
            RelationshipType::RelatesTo => (string) ($relationship->metadata['method'] ?? ''),
            RelationshipType::AuthorizedBy => (string) ($relationship->metadata['ability'] ?? ''),
            default => '',
        };
    }

    /**
     * Middleware is excluded: one class can register globally, in a group,
     * and under an alias, so a class name alone is ambiguous there. Routes
     * have no `class`.
     *
     * @param  array<string, mixed>  $artifacts
     * @return array<string, string>
     */
    private function buildClassIndex(array $artifacts): array
    {
        $index = [];

        foreach (ArtifactId::supportedTypes() as $type) {
            if ($type === 'middleware' || $type === 'routes') {
                continue;
            }

            foreach ((array) ($artifacts[$type] ?? []) as $artifact) {
                $id = is_array($artifact) ? ($artifact['id'] ?? null) : null;
                $class = is_array($artifact) ? ($artifact['class'] ?? null) : null;

                if (is_string($id) && $id !== '' && is_string($class) && $class !== '' && ! isset($index[$class])) {
                    $index[$class] = $id;
                }
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $artifacts
     * @return array<string, string>
     */
    private function buildBindingIndex(array $artifacts): array
    {
        $index = [];

        foreach ((array) ($artifacts['bindings'] ?? []) as $binding) {
            $id = is_array($binding) ? ($binding['id'] ?? null) : null;
            $abstract = is_array($binding) ? ($binding['abstract'] ?? null) : null;

            if (is_string($id) && $id !== '' && is_string($abstract) && $abstract !== '' && ! is_string($binding['consumer'] ?? null)) {
                $index[$abstract] ??= $id;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $artifacts
     * @return array<string, array<string, list<mixed>>>
     */
    private function buildControllerActionIndex(array $artifacts): array
    {
        $index = [];

        foreach ((array) ($artifacts['controllers'] ?? []) as $controller) {
            $class = is_array($controller) ? ($controller['class'] ?? null) : null;

            if (! is_string($class)) {
                continue;
            }

            foreach ((array) ($controller['actions'] ?? []) as $action) {
                if (is_array($action) && is_string($action['name'] ?? null)) {
                    $index[$class][$action['name']] = array_values((array) ($action['parameters'] ?? []));
                }
            }
        }

        return $index;
    }

    /**
     * A model's own `policy` (`#[UsePolicy]`) wins over a policy's guessed
     * `model`.
     *
     * @param  array<string, mixed>  $artifacts
     * @return array<string, string>
     */
    private function buildPolicyIndex(array $artifacts): array
    {
        $index = [];

        foreach ((array) ($artifacts['models'] ?? []) as $model) {
            if (is_array($model) && is_string($model['class'] ?? null) && is_string($model['policy'] ?? null) && $model['policy'] !== '') {
                $index[$model['class']] ??= $model['policy'];
            }
        }

        foreach ((array) ($artifacts['policies'] ?? []) as $policy) {
            if (is_array($policy) && is_string($policy['class'] ?? null) && is_string($policy['model'] ?? null) && $policy['model'] !== '') {
                $index[$policy['model']] ??= $policy['class'];
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $artifacts
     * @return array<string, string>
     */
    private function buildGateIndex(array $artifacts): array
    {
        $index = [];

        foreach ((array) ($artifacts['gates'] ?? []) as $gate) {
            if (is_array($gate) && is_string($gate['ability'] ?? null) && is_string($gate['id'] ?? null) && ! isset($index[$gate['ability']])) {
                $index[$gate['ability']] = $gate['id'];
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $artifacts
     * @return array<string, string>
     */
    private function buildRouteNameIndex(array $artifacts): array
    {
        $index = [];

        foreach ((array) ($artifacts['routes'] ?? []) as $route) {
            if (is_array($route) && is_string($route['name'] ?? null) && $route['name'] !== '' && is_string($route['id'] ?? null) && ! isset($index[$route['name']])) {
                $index[$route['name']] = $route['id'];
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $artifacts
     * @return list<array{id: string, class: string, scope: string, alias: string, group: string}>
     */
    private function buildMiddlewareIndex(array $artifacts): array
    {
        $index = [];

        foreach ((array) ($artifacts['middleware'] ?? []) as $artifact) {
            if (! is_array($artifact) || ! is_string($artifact['id'] ?? null) || $artifact['id'] === '') {
                continue;
            }

            $index[] = [
                'id' => $artifact['id'],
                'class' => (string) ($artifact['class'] ?? ''),
                'scope' => (string) ($artifact['scope'] ?? ''),
                'alias' => (string) ($artifact['alias'] ?? ''),
                'group' => (string) ($artifact['group'] ?? ''),
            ];
        }

        return $index;
    }
}
