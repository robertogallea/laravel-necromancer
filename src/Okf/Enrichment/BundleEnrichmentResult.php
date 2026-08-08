<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf\Enrichment;

final readonly class BundleEnrichmentResult
{
    private function __construct(
        public bool $successful,
        public ?string $outputPath,
        public int $conceptCount,
        public int $cachedCount,
        public int $freshCount,
        public ?string $error,
    ) {}

    public static function success(string $outputPath, int $conceptCount, int $cachedCount, int $freshCount): self
    {
        return new self(true, $outputPath, $conceptCount, $cachedCount, $freshCount, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, 0, 0, 0, $error);
    }
}
