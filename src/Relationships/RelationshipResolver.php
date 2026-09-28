<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

/**
 * The relationship taxonomy Necromancer already models as artifact fields —
 * route→controller, model→relationships/policy/observers, event→listeners,
 * listener→handles, policy→model, observer→model — expressed as structured
 * data rather than rendered Markdown. Framework-free and pure so both
 * LaravelNecromancer\Okf\ArtifactConceptBuilder (Markdown links) and a
 * future graph builder (structured edges) can consume the exact same
 * taxonomy without either duplicating it or parsing it back out of the
 * other's rendered output.
 */
final readonly class RelationshipResolver
{
    /**
     * @var list<string>
     */
    private const BUILTIN_TYPES = [
        'int', 'float', 'string', 'bool', 'array', 'iterable', 'callable', 'object', 'mixed',
        'null', 'false', 'true', 'void', 'never', 'self', 'static', 'parent',
    ];

    /**
     * @param  array<string, mixed>  $facts
     * @return list<RelationshipEdge>
     */
    public function resolve(string $type, array $facts): array
    {
        return match ($type) {
            'routes' => $this->scalarEdges(['controller' => $facts['controller'] ?? null]),
            'models' => [
                ...$this->modelRelationshipEdges($facts['relationships'] ?? []),
                ...$this->scalarEdges(['policy' => $facts['policy'] ?? null]),
                ...$this->listEdges(['observers' => $facts['observers'] ?? []]),
            ],
            'events' => $this->listEdges(['listeners' => $facts['listeners'] ?? []]),
            'listeners' => $this->listEdges(['handles' => $facts['handles'] ?? []]),
            'policies' => $this->scalarEdges(['model' => $facts['model'] ?? null]),
            'observers' => $this->scalarEdges(['model' => $facts['model'] ?? null]),
            'actions' => $this->listEdges(['operates_on' => $this->entrypointParameterClasses($facts['entrypoints'] ?? [])]),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $fields  label => single target value
     * @return list<RelationshipEdge>
     */
    private function scalarEdges(array $fields): array
    {
        $edges = [];

        foreach ($fields as $label => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $edges[] = new RelationshipEdge($label, [$value]);
        }

        return $edges;
    }

    /**
     * @param  array<string, mixed>  $fields  label => list of target values
     * @return list<RelationshipEdge>
     */
    private function listEdges(array $fields): array
    {
        $edges = [];

        foreach ($fields as $label => $values) {
            $targets = array_values(array_filter(
                (array) $values,
                fn (mixed $v): bool => is_string($v) && $v !== '',
            ));

            if ($targets === []) {
                continue;
            }

            $edges[] = new RelationshipEdge($label, $targets);
        }

        return $edges;
    }

    /**
     * @return list<RelationshipEdge>
     */
    private function modelRelationshipEdges(mixed $relationships): array
    {
        $edges = [];

        foreach ((array) $relationships as $relationship) {
            if (! is_array($relationship)) {
                continue;
            }

            $method = (string) ($relationship['method'] ?? '');
            $relatedType = (string) ($relationship['type'] ?? '');
            $related = $relationship['related'] ?? null;

            if ($method === '' || ! is_string($related) || $related === '') {
                continue;
            }

            $edges[] = new RelationshipEdge($method, [$related], $relatedType);
        }

        return $edges;
    }

    /**
     * Class types an action's entrypoints accept, deduplicated in first-seen
     * order. Nullable, union, and intersection types are split into their parts
     * and built-in types are dropped, since only classes can be other artifacts.
     *
     * @return list<string>
     */
    private function entrypointParameterClasses(mixed $entrypoints): array
    {
        $classes = [];

        foreach ((array) $entrypoints as $entrypoint) {
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

        return array_keys($classes);
    }
}
