<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

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
     */
    public function build(string $type, array $artifact, string $manifestGeneratedAt, array $classIndex = [], array $adrIndex = [], array $groupIndex = [], ?ConceptEnrichment $enrichment = null): ArtifactConcept
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

        $content = "---\n".FrontMatter::dump($frontMatter)."\n---\n\n".$this->body($title, $type, $facts, $annotations, $classIndex, $adrIndex, $groupIndex, $enrichment);

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
     * @param  array<string, ConceptLink>  $classIndex
     * @param  array<string, ConceptLink>  $adrIndex
     * @param  array<string, ConceptLink>  $groupIndex
     */
    private function body(string $title, string $type, array $facts, array $annotations, array $classIndex, array $adrIndex, array $groupIndex, ?ConceptEnrichment $enrichment): string
    {
        $lines = ["# {$title}", '', "_{$type} artifact_", ''];

        if ($annotations !== []) {
            $lines[] = '## Architectural Context';
            $lines[] = '';
            $lines[] = $this->architecturalContext($annotations, $adrIndex, $groupIndex);
            $lines[] = '';
        }

        $relationshipLines = $this->relationshipLines($type, $facts, $classIndex);

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
     * Relationship fields already present in $facts, rendered as a
     * dedicated section so declared structural links are as navigable as
     * the synthesized Domain/Flow concepts. Only the fields listed below
     * carry another artifact's identity — everything else stays plain
     * Discovered Facts.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, ConceptLink>  $classIndex
     * @return list<string>
     */
    private function relationshipLines(string $type, array $facts, array $classIndex): array
    {
        return match ($type) {
            'routes' => $this->scalarRelationshipLines(['controller' => $facts['controller'] ?? null], $classIndex),
            'models' => [
                ...$this->modelRelationshipLines($facts['relationships'] ?? [], $classIndex),
                ...$this->scalarRelationshipLines(['policy' => $facts['policy'] ?? null], $classIndex),
                ...$this->listRelationshipLines(['observers' => $facts['observers'] ?? []], $classIndex),
            ],
            'events' => $this->listRelationshipLines(['listeners' => $facts['listeners'] ?? []], $classIndex),
            'listeners' => $this->listRelationshipLines(['handles' => $facts['handles'] ?? []], $classIndex),
            'policies' => $this->scalarRelationshipLines(['model' => $facts['model'] ?? null], $classIndex),
            'observers' => $this->scalarRelationshipLines(['model' => $facts['model'] ?? null], $classIndex),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, ConceptLink>  $classIndex
     * @return list<string>
     */
    private function scalarRelationshipLines(array $fields, array $classIndex): array
    {
        $lines = [];

        foreach ($fields as $label => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $lines[] = "- **{$label}**: ".$this->linkOrText($value, $classIndex);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, ConceptLink>  $classIndex
     * @return list<string>
     */
    private function listRelationshipLines(array $fields, array $classIndex): array
    {
        $lines = [];

        foreach ($fields as $label => $values) {
            $values = array_values(array_filter(
                (array) $values,
                fn (mixed $v): bool => is_string($v) && $v !== '',
            ));

            if ($values === []) {
                continue;
            }

            $lines[] = "- **{$label}**: ".implode(', ', array_map(
                fn (string $v): string => $this->linkOrText($v, $classIndex),
                $values,
            ));
        }

        return $lines;
    }

    /**
     * @param  array<string, ConceptLink>  $classIndex
     * @return list<string>
     */
    private function modelRelationshipLines(mixed $relationships, array $classIndex): array
    {
        $lines = [];

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

            $lines[] = "- **{$method}**: {$relatedType} → ".$this->linkOrText($related, $classIndex);
        }

        return $lines;
    }

    /**
     * @param  array<string, ConceptLink>  $classIndex
     */
    private function linkOrText(string $value, array $classIndex): string
    {
        return isset($classIndex[$value]) ? "[{$value}]({$classIndex[$value]->path})" : $value;
    }
}
