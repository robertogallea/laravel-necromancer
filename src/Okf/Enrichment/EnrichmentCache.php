<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf\Enrichment;

/**
 * Per-concept-id cache of enrichment results, keyed additionally by a
 * content-derived key (see BundleEnricher) so an unchanged concept is never
 * re-sent to the AI provider, while a changed one invalidates only itself
 * rather than the whole run. Saves to disk after every set() — mirroring
 * LaravelNecromancer\Inference\AdrInferenceCache's pattern — so a run that
 * fails partway through resumes from cache for every concept already
 * completed rather than re-calling the provider for all of them.
 */
final class EnrichmentCache
{
    private string $cacheFile;

    /** @var array<string, array<string, mixed>> */
    private array $data = [];

    public function __construct(private readonly string $baseDir)
    {
        $this->cacheFile = $baseDir.'/.okf-enrichment-cache.json';
        $this->load();
    }

    public function has(string $conceptId, string $key): bool
    {
        return ($this->data[$conceptId]['key'] ?? null) === $key;
    }

    public function get(string $conceptId, string $key): ?RawEnrichment
    {
        if (! $this->has($conceptId, $key)) {
            return null;
        }

        $entry = $this->data[$conceptId];

        return new RawEnrichment(
            description: (string) ($entry['description'] ?? ''),
            narrative: (string) ($entry['narrative'] ?? ''),
            promptTokens: (int) ($entry['prompt_tokens'] ?? 0),
            completionTokens: (int) ($entry['completion_tokens'] ?? 0),
        );
    }

    public function set(string $conceptId, string $key, RawEnrichment $result): void
    {
        $this->data[$conceptId] = [
            'key' => $key,
            'description' => $result->description,
            'narrative' => $result->narrative,
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
        ];

        $this->save();
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->save();
    }

    private function load(): void
    {
        if (! file_exists($this->cacheFile)) {
            return;
        }

        $contents = file_get_contents($this->cacheFile);

        if ($contents === false) {
            return;
        }

        try {
            $this->data = (array) json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->data = [];
        }
    }

    private function save(): void
    {
        if (! is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0755, true);
        }

        file_put_contents(
            $this->cacheFile,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
