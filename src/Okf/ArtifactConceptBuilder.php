<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

use LaravelNecromancer\Relationships\Relationship;
use LaravelNecromancer\Relationships\RelationshipType;
use LaravelNecromancer\Relationships\TestMatch;

/**
 * Projects one serialized manifest artifact into a portable OKF 0.2 Artifact
 * Concept: YAML front matter (authoritative) plus a concise Markdown body
 * (a fallback for consumers that strip front matter). Pure and deterministic
 * — the same artifact array and manifest generated_at always produce the
 * same ArtifactConcept, which the exporter's reproducibility guarantee
 * depends on.
 */
final readonly class ArtifactConceptBuilder
{
    /**
     * The Discovered Facts exclusion list — also the privacy boundary
     * LaravelNecromancer\Okf\Enrichment\EnrichmentPromptBuilder reuses
     * verbatim, so an enrichment prompt can never see more of an artifact
     * than its own Artifact Concept body does.
     *
     * @var list<string>
     */
    public const EXCLUDED_FACT_KEYS = ['id', 'annotations', 'source', 'route_metadata'];

    /**
     * The facts an Artifact Concept's Relationships section renders, keyed
     * to the label each is shown under. Only facts the artifact itself
     * holds are rendered (a model's `policy`, a policy's `model`), even
     * though one Relationship merges evidence from both ends. `relationships`
     * is labelled per Eloquent method instead. Entries are in render order.
     *
     * @var array<string, string>
     */
    private const RENDERED_FIELDS = [
        'controller' => 'controller',
        'relationships' => 'relationships',
        'policy' => 'policy',
        'observers' => 'observers',
        'listeners' => 'listeners',
        'handles' => 'handles',
        'model' => 'model',
        'entrypoints' => 'operates_on',
    ];

    /**
     * The Relationship types rendered on their `from` artifact's concept,
     * labelled by type name, after every RENDERED_FIELDS line (see
     * docs/adr/0021). Entries are in render order.
     *
     * @var list<RelationshipType>
     */
    private const SOURCE_SIDE_TYPES = [
        RelationshipType::UsesMiddleware,
        RelationshipType::ValidatesWith,
        RelationshipType::AuthorizedBy,
        RelationshipType::Dispatches,
        RelationshipType::TestedBy,
    ];

    /**
     * Identity only — no facts/annotations rendering. Cheap enough to call
     * for every artifact up front, so BundleExporter can build a class index
     * and member links before any concept body is rendered.
     *
     * @param  array<string, mixed>  $artifact
     * @return array{id: string, title: string, filename: string}
     */
    public function identify(string $type, array $artifact): array
    {
        $id = (string) ($artifact['id'] ?? '');
        $title = $this->title($type, $artifact);

        return ['id' => $id, 'title' => $title, 'filename' => ConceptFilename::make($title, $id)];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, ConceptLink>  $classIndex  FQCN/controller → link, for rendering relationship fields
     * @param  array<string, ConceptLink>  $adrIndex  local ADR path → link, for rendering declared adrs
     * @param  array<string, ConceptLink>  $groupIndex  "domain:value"/"flow:value" → link, for linking back to the synthesized group concept
     * @param  list<Relationship>  $relationships  the manifest's Relationships (any neither evidenced by this artifact nor starting from it are ignored)
     * @param  array<string, ConceptLink>  $idIndex  Artifact ID → link, for rendering source-side Relationship targets
     */
    public function build(string $type, array $artifact, string $manifestGeneratedAt, array $classIndex = [], array $adrIndex = [], array $groupIndex = [], ?ConceptEnrichment $enrichment = null, array $relationships = [], array $idIndex = []): ArtifactConcept
    {
        $identity = $this->identify($type, $artifact);
        $id = $identity['id'];
        $title = $identity['title'];
        $annotations = is_array($artifact['annotations'] ?? null) ? $artifact['annotations'] : [];
        $facts = array_diff_key($artifact, array_flip(self::EXCLUDED_FACT_KEYS));

        $source = is_array($artifact['source'] ?? null) ? array_filter([
            'file' => $artifact['source']['file'] ?? null,
            'line' => $artifact['source']['line'] ?? null,
        ], fn (mixed $v): bool => $v !== null) : [];

        $frontMatter = [
            'title' => $title,
            'type' => 'artifact',
            'kind' => $type,
            'summary' => $annotations['summary'] ?? null,
            'description' => $enrichment?->description,
            'tags' => $this->tags($annotations),
            'necromancer' => [
                'schema_version' => 1,
                'bundle_version' => '0.2',
                'id' => $id,
                'artifact_type' => $type,
                'generated_at' => $manifestGeneratedAt,
                'source' => $source,
                'framework_metadata' => is_array($artifact['route_metadata'] ?? null) ? $artifact['route_metadata'] : [],
                'facts' => $facts,
                'annotations' => $annotations,
                'enrichment' => $enrichment?->toFrontMatter() ?? [],
            ],
        ];

        $relationshipLines = [...$this->relationshipLines($id, $relationships, $classIndex), ...$this->sourceSideLines($type, $id, $relationships, $idIndex)];
        $content = "---\n".FrontMatter::dump($frontMatter)."\n---\n\n".$this->body($title, $type, $facts, $annotations, $relationshipLines, $adrIndex, $groupIndex, $enrichment);

        return new ArtifactConcept($id, $identity['filename'], $content);
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function title(string $type, array $artifact): string
    {
        return match ($type) {
            'routes' => trim(($artifact['method'] ?? '').' '.($artifact['uri'] ?? '')),
            'tests' => (string) ($artifact['file'] ?? $artifact['id'] ?? ''),
            'gates' => (string) ($artifact['ability'] ?? ''),
            'scheduled_tasks' => (string) ($artifact['command'] ?? ''),
            'middleware' => ($artifact['class'] ?? '').' ('.($artifact['scope'] ?? '').')',
            default => (string) ($artifact['class'] ?? $artifact['signature'] ?? $artifact['id'] ?? $type),
        };
    }

    /**
     * @param  array<string, mixed>  $annotations
     * @return list<string>
     */
    private function tags(array $annotations): array
    {
        $tags = [];

        foreach (['domain', 'flow', 'capability'] as $field) {
            if (! empty($annotations[$field] ?? null)) {
                $tags[] = (string) $annotations[$field];
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $annotations
     * @param  list<string>  $relationshipLines
     * @param  array<string, ConceptLink>  $adrIndex
     * @param  array<string, ConceptLink>  $groupIndex
     */
    private function body(string $title, string $type, array $facts, array $annotations, array $relationshipLines, array $adrIndex, array $groupIndex, ?ConceptEnrichment $enrichment): string
    {
        $lines = ["# {$title}", '', "_{$type} artifact_", ''];

        if ($annotations !== []) {
            $lines[] = '## Architectural Context';
            $lines[] = '';
            $lines[] = $this->architecturalContext($annotations, $adrIndex, $groupIndex);
            $lines[] = '';
        }

        if ($relationshipLines !== []) {
            $lines[] = '## Relationships';
            $lines[] = '';
            $lines = [...$lines, ...$relationshipLines, ''];
        }

        $lines[] = '## Discovered Facts';
        $lines[] = '';

        $factLines = array_values(array_filter(array_map(
            fn (string $key, mixed $value): ?string => $this->factLine($key, $value),
            array_keys($facts),
            $facts,
        )));

        $lines = [...$lines, ...($factLines !== [] ? $factLines : ['_No discovered facts._'])];

        if ($enrichment !== null) {
            $lines = [...$lines, '', '## AI-Enriched Summary', '', $enrichment->narrative];
        }

        return implode("\n", $lines);
    }

    private function factLine(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $rendered = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            default => (string) $value,
        };

        return "- **{$key}**: `{$rendered}`";
    }

    /**
     * @param  array<string, mixed>  $annotations
     * @param  array<string, ConceptLink>  $adrIndex
     * @param  array<string, ConceptLink>  $groupIndex
     */
    private function architecturalContext(array $annotations, array $adrIndex, array $groupIndex): string
    {
        $parts = [];

        foreach (['domain', 'flow'] as $field) {
            if (! empty($annotations[$field] ?? null) && is_string($annotations[$field])) {
                $parts[] = "{$field}: ".$this->groupDisplay($field, $annotations[$field], $groupIndex);
            }
        }

        if (! empty($annotations['capability'] ?? null)) {
            $parts[] = "capability: {$annotations['capability']}";
        }

        if (! empty($annotations['summary'] ?? null)) {
            $parts[] = 'summary: '.$annotations['summary'];
        }

        if (! empty($annotations['risk'] ?? null)) {
            $parts[] = 'risk: '.$annotations['risk'];
        }

        if (! empty($annotations['external_services'] ?? null)) {
            $parts[] = 'external services: '.implode(', ', $annotations['external_services']);
        }

        if (! empty($annotations['adrs'] ?? null)) {
            $parts[] = 'adrs: '.implode(', ', array_map(
                fn (mixed $adr): string => is_string($adr) ? $this->adrDisplay($adr, $adrIndex) : (string) $adr,
                $annotations['adrs'],
            ));
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, ConceptLink>  $adrIndex
     */
    private function adrDisplay(string $adr, array $adrIndex): string
    {
        if (isset($adrIndex[$adr])) {
            return "[{$adr}]({$adrIndex[$adr]->path})";
        }

        if (UriReference::isAbsolute($adr)) {
            return "[{$adr}]({$adr})";
        }

        return $adr;
    }

    /**
     * Links a declared domain/flow value back to its synthesized group
     * concept, so navigation isn't one-directional (Domain/Flow → members
     * only) — a reader landing on this artifact can click through to every
     * other artifact sharing the same value.
     *
     * @param  array<string, ConceptLink>  $groupIndex
     */
    private function groupDisplay(string $field, string $value, array $groupIndex): string
    {
        $link = $groupIndex["{$field}:{$value}"] ?? null;

        return $link !== null ? "[{$value}]({$link->path})" : $value;
    }

    /**
     * One line per Eloquent relationship method, and one line per other
     * rendered fact listing every value it names — each linked when the
     * named class has a concept in the bundle, plain text otherwise. Lines
     * follow RENDERED_FIELDS order and each fact's own value order, never
     * the resolver's order, so they match the artifact's declared facts.
     *
     * @param  list<Relationship>  $relationships
     * @param  array<string, ConceptLink>  $classIndex
     * @return list<string>
     */
    private function relationshipLines(string $id, array $relationships, array $classIndex): array
    {
        if ($id === '') {
            return [];
        }

        $rendered = [];

        foreach ($relationships as $relationship) {
            foreach ($relationship->evidence as $evidence) {
                if ($evidence->artifact === $id && isset(self::RENDERED_FIELDS[$evidence->field])) {
                    $rendered[] = [$evidence, $relationship];
                }
            }
        }

        $fieldOrder = array_flip(array_keys(self::RENDERED_FIELDS));

        usort($rendered, fn (array $a, array $b): int => [$fieldOrder[$a[0]->field], $a[0]->position] <=> [$fieldOrder[$b[0]->field], $b[0]->position]);

        $lines = [];

        foreach ($rendered as [$evidence, $relationship]) {
            if ($evidence->field === 'relationships') {
                $lines[] = ['label' => (string) $relationship->metadata['method'], 'kind' => (string) $relationship->metadata['kind'], 'values' => [$evidence->value]];

                continue;
            }

            $lines[$evidence->field] ??= ['label' => self::RENDERED_FIELDS[$evidence->field], 'kind' => null, 'values' => []];
            $lines[$evidence->field]['values'][] = $evidence->value;
        }

        return array_values(array_map(fn (array $line): string => $this->relationshipLine($line, $classIndex), $lines));
    }

    /**
     * @param  array{label: string, kind: string|null, values: list<string>}  $line
     * @param  array<string, ConceptLink>  $classIndex
     */
    private function relationshipLine(array $line, array $classIndex): string
    {
        $targets = implode(', ', array_map(
            fn (string $target): string => $this->linkOrText($target, $classIndex),
            $line['values'],
        ));

        $value = $line['kind'] !== null ? "{$line['kind']} → {$targets}" : $targets;

        return "- **{$line['label']}**: {$value}";
    }

    /**
     * @param  array<string, ConceptLink>  $classIndex
     */
    private function linkOrText(string $value, array $classIndex): string
    {
        return isset($classIndex[$value]) ? "[{$value}]({$classIndex[$value]->path})" : $value;
    }

    /**
     * One line per SOURCE_SIDE_TYPES type this artifact is the `from` of,
     * listing every target in resolver order — linked by Artifact ID when
     * the target has a concept, plain text otherwise — each followed by its
     * qualifier, if any. A model's authorized_by stays its legacy `policy`
     * line, so only a route renders authorized_by here.
     *
     * @param  list<Relationship>  $relationships
     * @param  array<string, ConceptLink>  $idIndex
     * @return list<string>
     */
    private function sourceSideLines(string $artifactType, string $id, array $relationships, array $idIndex): array
    {
        if ($id === '') {
            return [];
        }

        $lines = [];

        foreach (self::SOURCE_SIDE_TYPES as $relationshipType) {
            if ($relationshipType === RelationshipType::AuthorizedBy && $artifactType !== 'routes') {
                continue;
            }

            $targets = [];

            foreach ($relationships as $relationship) {
                if ($relationship->from !== $id || $relationship->type !== $relationshipType) {
                    continue;
                }

                $link = $idIndex[$relationship->to] ?? null;
                $target = $link !== null ? "[{$link->title}]({$link->path})" : $relationship->to;
                $qualifier = $this->qualifier($relationship);

                $targets[] = $qualifier !== '' ? "{$target} ({$qualifier})" : $target;
            }

            if ($targets !== []) {
                $lines[] = "- **{$relationshipType->value}**: ".implode(', ', $targets);
            }
        }

        return $lines;
    }

    private function qualifier(Relationship $relationship): string
    {
        return match ($relationship->type) {
            RelationshipType::UsesMiddleware => ($relationship->metadata['groups'] ?? []) !== [] ? 'via '.implode(', ', $relationship->metadata['groups']) : '',
            RelationshipType::AuthorizedBy => (string) ($relationship->metadata['ability'] ?? ''),
            RelationshipType::Dispatches => implode(', ', $relationship->metadata['modes'] ?? []),
            RelationshipType::TestedBy => TestMatch::qualifierFor($relationship->metadata['match'] ?? null),
            default => '',
        };
    }
}
