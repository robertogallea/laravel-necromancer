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
}
