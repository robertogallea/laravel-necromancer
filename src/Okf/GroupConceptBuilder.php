<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

/**
 * Synthesizes a Domain or Flow Concept: a concept with no artifact of its
 * own, existing only to make every artifact sharing one `domain`/`flow`
 * annotation value navigable as a group. Pure and deterministic — member
 * order never affects output, since the manifest itself imposes no
 * particular grouping order across artifact types.
 */
final readonly class GroupConceptBuilder
{
    /**
     * @return array{id: string, title: string, filename: string}
     */
    public function identify(string $kind, string $value): array
    {
        $id = "{$kind}:{$value}";

        return ['id' => $id, 'title' => $value, 'filename' => ConceptFilename::make($value, $id)];
    }

    /**
     * @param  array<string, ConceptLink>  $members  keyed by member artifact id
     */
    public function build(string $kind, string $value, array $members, string $generatedAt): ArtifactConcept
    {
        $identity = $this->identify($kind, $value);
        ksort($members);

        $frontMatter = [
            'title' => $value,
            'type' => $kind,
            'necromancer' => [
                'schema_version' => 1,
                'bundle_version' => '0.2',
                'id' => $identity['id'],
                'concept_type' => $kind,
                'generated_at' => $generatedAt,
                'members' => array_keys($members),
            ],
        ];

        $lines = ["# {$value}", '', "_{$kind} concept_", '', '## Artifacts', ''];

        $lines = [
            ...$lines,
            ...($members !== []
                ? array_map(fn (ConceptLink $link): string => $link->toMarkdownListItem(), array_values($members))
                : ['_No member artifacts._']),
        ];

        $content = "---\n".FrontMatter::dump($frontMatter)."\n---\n\n".implode("\n", $lines);

        return new ArtifactConcept($identity['id'], $identity['filename'], $content);
    }
}
