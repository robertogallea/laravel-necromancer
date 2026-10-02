<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

use LaravelNecromancer\Manifest\ArtifactId;

/**
 * Computes an Impact by walking the manifest's Relationships breadth-first
 * from a starting artifact (docs/adr/0022). Framework-free, so every
 * consumer (necromancer:impact, MCP, affected-test discovery) shares one
 * traversal rule.
 */
final readonly class ImpactAnalyzer
{
    /**
     * Node types reported in an Impact but never expanded past.
     *
     * @var list<string>
     */
    private const BOUNDARY_TYPES = ['domain', 'flow', 'adr', 'middleware', 'tests'];

    public function __construct(
        private RelationshipResolver $relationships = new RelationshipResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function analyze(array $manifest, string $start, int $depth): Impact
    {
        $types = $this->artifactTypes($manifest);
        $relationships = $this->relationships->resolve($manifest);
        $visited = [$start => true];
        $frontier = [$start => true];
        $nodes = [];

        for ($distance = 1; $distance <= $depth && $frontier !== []; $distance++) {
            $next = [];

            foreach ($relationships as $relationship) {
                [$id, $viaFrom, $direction] = match (true) {
                    isset($frontier[$relationship->from]) && ! isset($visited[$relationship->to]) => [$relationship->to, $relationship->from, 'out'],
                    isset($frontier[$relationship->to]) && ! isset($visited[$relationship->from]) => [$relationship->from, $relationship->to, 'in'],
                    default => [null, null, null],
                };

                if ($id === null) {
                    continue;
                }

                $type = $types[$id] ?? $this->conceptType($relationship, $id);
                $visited[$id] = true;
                $nodes[] = new ImpactNode($id, $type, $distance, $type !== null, $relationship, $viaFrom, $direction);

                if ($type !== null && ! in_array($type, self::BOUNDARY_TYPES, true)) {
                    $next[$id] = true;
                }
            }

            $frontier = $next;
        }

        return new Impact($start, $depth, $nodes);
    }

    /**
     * The Artifact IDs a start input can denote: the exact Artifact ID when
     * one matches, otherwise every artifact whose class is that FQCN (a
     * middleware registered under several scopes yields several). Empty
     * when nothing matches.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public function startCandidates(array $manifest, string $input): array
    {
        if (isset($this->artifactTypes($manifest)[$input])) {
            return [$input];
        }

        $class = ltrim($input, '\\');
        $candidates = [];

        foreach (ArtifactId::supportedTypes() as $type) {
            foreach ((array) ($manifest['artifacts'][$type] ?? []) as $artifact) {
                if (is_array($artifact) && ($artifact['class'] ?? null) === $class && is_string($artifact['id'] ?? null)) {
                    $candidates[] = $artifact['id'];
                }
            }
        }

        return $candidates;
    }

    /**
     * The synthesized Domain/Flow/ADR type of a Relationship's `to`, or
     * null when the end is simply not a collected artifact.
     */
    private function conceptType(Relationship $relationship, string $id): ?string
    {
        if ($id !== $relationship->to) {
            return null;
        }

        return match ($relationship->type) {
            RelationshipType::BelongsToDomain => 'domain',
            RelationshipType::BelongsToFlow => 'flow',
            RelationshipType::ReferencesAdr => 'adr',
            default => null,
        };
    }

    /**
     * Artifact ID → artifact type, for every collected artifact.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private function artifactTypes(array $manifest): array
    {
        $types = [];

        foreach (ArtifactId::supportedTypes() as $type) {
            foreach ((array) ($manifest['artifacts'][$type] ?? []) as $artifact) {
                if (is_array($artifact) && is_string($artifact['id'] ?? null)) {
                    $types[$artifact['id']] = $type;
                }
            }
        }

        return $types;
    }
}
