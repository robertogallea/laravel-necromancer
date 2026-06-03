<?php

declare(strict_types=1);

namespace LaravelNecromancer\Diff;

final readonly class ManifestDiff
{
    /**
     * @param  array<string, list<array<string,mixed>>>  $added   artifacts only in head
     * @param  array<string, list<array<string,mixed>>>  $removed artifacts only in base
     * @param  array<string, list<array{from: array<string,mixed>, to: array<string,mixed>}>>  $changed artifacts in both but different
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $changed,
    ) {}

    public function isEmpty(): bool
    {
        return $this->totalAdditions() === 0
            && $this->totalRemovals() === 0
            && $this->totalChanges() === 0;
    }

    public function totalAdditions(): int
    {
        return array_sum(array_map('count', $this->added));
    }

    public function totalRemovals(): int
    {
        return array_sum(array_map('count', $this->removed));
    }

    public function totalChanges(): int
    {
        return array_sum(array_map('count', $this->changed));
    }
}
