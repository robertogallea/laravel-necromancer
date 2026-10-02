<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

use Generator;
use LaravelNecromancer\Manifest\ArtifactId;
use LaravelNecromancer\Okf\ArtifactConceptBuilder;

/**
 * Computes an Impact by walking the manifest's Relationships breadth-first
 * from a starting artifact. Framework-free, so every consumer shares the
 * one traversal rule of docs/adr/0022.
 */
final readonly class ImpactAnalyzer
{
    /**
     * Node types synthesized from annotations rather than collected.
     *
     * @var list<string>
     */
    public const CONCEPT_TYPES = ['domain', 'flow', 'adr'];

    /**
     * Node types reported in an Impact but never expanded past.
     *
     * @var list<string>
     */
    private const BOUNDARY_TYPES = [...self::CONCEPT_TYPES, 'middleware', 'tests'];

    /**
     * Every node type an Impact can report, in display order: the
     * supported artifact types, then the synthesized concept types. The
     * allowed values of necromancer:impact --type and get_impact's types.
     *
     * @return list<string>
     */
    public static function nodeTypes(): array
    {
        return [...ArtifactId::supportedTypes(), ...self::CONCEPT_TYPES];
    }

    /**
     * Whether an ID names a synthesized Domain/Flow/ADR rather than a
     * collected artifact.
     */
    public static function isConceptId(string $id): bool
    {
        return in_array(strstr($id, ':', true), self::CONCEPT_TYPES, true);
    }

    /**
     * ArtifactConceptBuilder::identify() is the package's one per-type
     * display-label convention, reused as ArtifactGraphBuilder reuses it.
     */
    public function __construct(
        private RelationshipResolver $relationships = new RelationshipResolver,
        private ArtifactConceptBuilder $conceptBuilder = new ArtifactConceptBuilder,
    ) {}

    /**
     * Walks level by level: for each distance, the first Relationship in
     * canonical order linking the frontier to an unvisited node reaches it,
     * so ties break by canonical order and nodes come out already sorted.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function analyze(array $manifest, string $start, int $depth): Impact
    {
        $types = [];
        $labels = [];

        foreach ($this->artifacts($manifest) as $type => [$id, $artifact]) {
            $types[$id] = $type;
            $labels[$id] = $this->conceptBuilder->identify($type, $artifact)['title'];
        }

        $relationships = $this->relationships->resolve($manifest);
        $visited = [$start => true];
        $frontier = [$start => true];
        $nodes = [];

        for ($distance = 1; $distance <= $depth && $frontier !== []; $distance++) {
            $next = [];

            foreach ($relationships as $relationship) {
                [$id, $viaFrom, $direction] = match (true) {
                    isset($frontier[$relationship->from]) && ! isset($visited[$relationship->to]) => [$relationship->to, $relationship->from, ImpactDirection::Out],
                    isset($frontier[$relationship->to]) && ! isset($visited[$relationship->from]) => [$relationship->from, $relationship->to, ImpactDirection::In],
                    default => [null, null, null],
                };

                if ($id === null) {
                    continue;
                }

                $type = $types[$id] ?? $this->conceptType($relationship, $id);
                $visited[$id] = true;
                $nodes[] = new ImpactNode($id, $type, $distance, $relationship, $viaFrom, $direction);

                if ($type !== null && ! in_array($type, self::BOUNDARY_TYPES, true)) {
                    $next[$id] = true;
                }
            }

            $frontier = $next;
        }

        return new Impact($start, $depth, $nodes, $labels);
    }

    /**
     * The Artifact IDs a start input can denote: the exact Artifact ID when
     * one matches, otherwise every artifact whose class is that FQCN (a
     * middleware registered under several scopes yields several). A
     * Domain/Flow/ADR ID denotes itself when at least one Relationship
     * targets it. Empty when nothing matches.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public function startCandidates(array $manifest, string $input): array
    {
        if (self::isConceptId($input)) {
            foreach ($this->relationships->resolve($manifest) as $relationship) {
                if ($relationship->to === $input) {
                    return [$input];
                }
            }

            return [];
        }

        $class = ltrim($input, '\\');
        $candidates = [];

        foreach ($this->artifacts($manifest) as [$id, $artifact]) {
            if ($id === $input) {
                return [$input];
            }

            if (($artifact['class'] ?? null) === $class) {
                $candidates[] = $id;
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
     * Every collected artifact carrying an Artifact ID, as [id, artifact]
     * keyed by its type, in canonical order.
     *
     * @param  array<string, mixed>  $manifest
     * @return Generator<string, array{string, array<string, mixed>}>
     */
    private function artifacts(array $manifest): Generator
    {
        foreach (ArtifactId::supportedTypes() as $type) {
            foreach ((array) ($manifest['artifacts'][$type] ?? []) as $artifact) {
                if (is_array($artifact) && is_string($artifact['id'] ?? null)) {
                    yield $type => [$artifact['id'], $artifact];
                }
            }
        }
    }
}
