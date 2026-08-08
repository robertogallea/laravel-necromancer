<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

use Illuminate\Support\Str;

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
    /** @var list<string> */
    private const EXCLUDED_FACT_KEYS = ['id', 'annotations', 'source', 'route_metadata'];

    /**
     * @param  array<string, mixed>  $artifact
     */
    public function build(string $type, array $artifact, string $manifestGeneratedAt): ArtifactConcept
    {
        $id = (string) ($artifact['id'] ?? '');
        $title = $this->title($type, $artifact);
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
            ],
        ];

        $content = "---\n".FrontMatter::dump($frontMatter)."\n---\n\n".$this->body($title, $type, $facts, $annotations);

        return new ArtifactConcept($id, $this->filename($id, $title), $content);
    }

    private function filename(string $id, string $title): string
    {
        // Str::slug() drops backslashes rather than treating them as word
        // boundaries, so a class-derived title would collapse into one
        // unreadable run (e.g. "appjobssendinvoice"); replacing them with
        // spaces first keeps namespace segments distinct in the slug.
        $slug = Str::slug(str_replace('\\', ' ', $title));
        $hash = substr(hash('sha256', $id), 0, 8);

        return ($slug !== '' ? "{$slug}-" : '')."{$hash}.md";
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
     */
    private function body(string $title, string $type, array $facts, array $annotations): string
    {
        $lines = ["# {$title}", '', "_{$type} artifact_", ''];

        if ($annotations !== []) {
            $lines[] = '## Architectural Context';
            $lines[] = '';
            $lines[] = $this->architecturalContext($annotations);
            $lines[] = '';
        }

        $lines[] = '## Discovered Facts';
        $lines[] = '';

        $factLines = array_values(array_filter(array_map(
            fn (string $key, mixed $value): ?string => $this->factLine($key, $value),
            array_keys($facts),
            $facts,
        )));

        return implode("\n", [...$lines, ...($factLines !== [] ? $factLines : ['_No discovered facts._'])]);
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
     */
    private function architecturalContext(array $annotations): string
    {
        $parts = [];

        foreach (['domain', 'flow', 'capability'] as $field) {
            if (! empty($annotations[$field] ?? null)) {
                $parts[] = "{$field}: {$annotations[$field]}";
            }
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
            $parts[] = 'adrs: '.implode(', ', $annotations['adrs']);
        }

        return implode(' · ', $parts);
    }
}
