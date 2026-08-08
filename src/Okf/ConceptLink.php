<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

/**
 * A resolved reference to another concept in the same bundle: a display
 * title and a bundle-relative path (e.g. "/artifacts/foo-1a2b3c4d.md").
 */
final readonly class ConceptLink
{
    public function __construct(
        public string $title,
        public string $path,
    ) {}

    /**
     * A Markdown bullet linking to this concept by its own title — the
     * common rendering shared by the Domain/Flow "## Artifacts" list and
     * the ADR "## Referenced By" list.
     */
    public function toMarkdownListItem(): string
    {
        return "- [{$this->title}]({$this->path})";
    }
}
