<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

final readonly class GraphExportResult
{
    private function __construct(
        public bool $successful,
        public ?string $outputPath,
        public int $nodeCount,
        public ?string $error,
    ) {}

    public static function success(string $outputPath, int $nodeCount): self
    {
        return new self(true, $outputPath, $nodeCount, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, 0, $error);
    }
}
