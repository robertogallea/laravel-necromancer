<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

final readonly class ArtifactConcept
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $content,
    ) {}
}
