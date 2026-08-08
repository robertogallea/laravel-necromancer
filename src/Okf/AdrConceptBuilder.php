<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

/**
 * Copies one locally declared ADR into the bundle as its own concept, with
 * provenance (the source path it was copied from) and the artifacts that
 * declared it. The original file content is mirrored verbatim in the body
 * so the bundle stays useful even to a consumer that never opens the
 * referencing artifact concepts.
 */
final readonly class AdrConceptBuilder
{
    /**
     * Identity only — derived from the path alone, so the exporter can
     * resolve links to an ADR concept before reading its file content.
     *
     * @return array{id: string, title: string, filename: string}
     */
    public function identify(string $path): array
    {
        $title = pathinfo($path, PATHINFO_FILENAME);
        $id = "adr:{$path}";

        return ['id' => $id, 'title' => $title, 'filename' => ConceptFilename::make($title, $id)];
    }

    /**
     * @param  array<string, ConceptLink>  $referencedBy  keyed by referencing artifact id
     */
    public function build(string $path, string $content, array $referencedBy, string $generatedAt): ArtifactConcept
    {
        $identity = $this->identify($path);
        ksort($referencedBy);

        $frontMatter = [
            'title' => $identity['title'],
            'type' => 'adr',
            'necromancer' => [
                'schema_version' => 1,
                'bundle_version' => '0.2',
                'id' => $identity['id'],
                'concept_type' => 'adr',
                'generated_at' => $generatedAt,
                'source' => ['file' => $path],
                'referenced_by' => array_keys($referencedBy),
            ],
        ];

        $lines = ["# {$identity['title']}", '', '_adr concept_', '', '## Referenced By', ''];

        $lines = [
            ...$lines,
            ...($referencedBy !== []
                ? array_map(fn (ConceptLink $link): string => $link->toMarkdownListItem(), array_values($referencedBy))
                : ['_No referencing artifacts._']),
            '',
            '---',
            '',
            rtrim($content),
        ];

        $body = "---\n".FrontMatter::dump($frontMatter)."\n---\n\n".implode("\n", $lines);

        return new ArtifactConcept($identity['id'], $identity['filename'], $body);
    }
}
