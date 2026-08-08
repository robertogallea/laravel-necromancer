<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

final readonly class BundleExportResult
{
    private function __construct(
        public bool $successful,
        public ?string $outputPath,
        public int $artifactCount,
        public ?string $error,
    ) {}

    public static function success(string $outputPath, int $artifactCount): self
    {
        return new self(true, $outputPath, $artifactCount, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, 0, $error);
    }
}
