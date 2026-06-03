<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit;

final readonly class Finding
{
    public function __construct(
        public string $severity,
        public string $message,
        public string $artifactType,
        public string $context,
        public ?string $source = null,
    ) {}
}
