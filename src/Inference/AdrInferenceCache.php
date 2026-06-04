<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference;

final class AdrInferenceCache
{
    private string $cacheFile;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $baseDir)
    {
        $this->cacheFile = $baseDir.'/.adr-inference-cache.json';
        $this->load();
    }

    public function hasCanonical(string $key): bool
    {
        return ($this->data['key'] ?? null) === $key
            && isset($this->data['canonical']);
    }

    public function getCanonical(string $key): ?AdrInferenceResult
    {
        if (! $this->hasCanonical($key)) {
            return null;
        }

        return $this->hydrateResult($this->data['canonical']);
    }

    public function setCanonical(string $key, AdrInferenceResult $result): void
    {
        if (($this->data['key'] ?? null) !== $key) {
            $this->data = ['key' => $key, 'translations' => []];
        }

        $this->data['canonical'] = $this->dehydrateResult($result);
        $this->save();
    }

    public function hasTranslation(string $key, string $locale): bool
    {
        return ($this->data['key'] ?? null) === $key
            && isset($this->data['translations'][$locale]);
    }

    public function getTranslation(string $key, string $locale): ?AdrInferenceResult
    {
        if (! $this->hasTranslation($key, $locale)) {
            return null;
        }

        return $this->hydrateResult($this->data['translations'][$locale]);
    }

    public function setTranslation(string $key, string $locale, AdrInferenceResult $result): void
    {
        if (($this->data['key'] ?? null) !== $key) {
            return;
        }

        $this->data['translations'][$locale] = $this->dehydrateResult($result);
        $this->save();
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->save();
    }

    /** @return array<string, mixed> */
    private function dehydrateResult(AdrInferenceResult $result): array
    {
        return [
            'adrs' => array_map(fn (InferredAdr $adr) => [
                'title' => $adr->title,
                'slug' => $adr->slug,
                'status' => $adr->status,
                'context' => $adr->context,
                'decision' => $adr->decision,
                'consequences' => $adr->consequences,
                'dimension' => $adr->dimension,
                'confidence' => $adr->confidence,
            ], $result->adrs),
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
        ];
    }

    /** @param array<string, mixed> $data */
    private function hydrateResult(array $data): AdrInferenceResult
    {
        return new AdrInferenceResult(
            adrs: array_map(
                fn (array $a) => new InferredAdr(
                    title: $a['title'],
                    slug: $a['slug'],
                    status: $a['status'],
                    context: $a['context'],
                    decision: $a['decision'],
                    consequences: $a['consequences'],
                    dimension: $a['dimension'] ?? 'general',
                    confidence: $a['confidence'] ?? 'medium',
                ),
                $data['adrs'] ?? [],
            ),
            promptTokens: (int) ($data['prompt_tokens'] ?? 0),
            completionTokens: (int) ($data['completion_tokens'] ?? 0),
        );
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
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }
}
