<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Inference\AdrInferenceCache;
use LaravelNecromancer\Inference\AdrInferenceResult;
use LaravelNecromancer\Inference\AdrWriter;
use LaravelNecromancer\Inference\Contracts\AdrCritic;
use LaravelNecromancer\Inference\Contracts\AdrInferrer;
use LaravelNecromancer\Inference\Contracts\AdrTranslator;
use LaravelNecromancer\Inference\InferredAdr;
use LaravelNecromancer\Inference\ManifestSummarizer;
use LaravelNecromancer\Integrations\AiDetector;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class InferCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:infer
        {--locale=      : Comma-separated locale code(s) to translate ADRs into (e.g. it or fr,it). The default app locale is always generated first.}
        {--temperature= : LLM temperature (0.0–2.0). Lower = more deterministic. Omit to use the provider default.}
        {--max-critic-rounds=1 : Maximum number of critic review rounds (exits early if critic is satisfied)}
        {--dry-run      : Print inferred ADRs to the terminal without writing files}
        {--force        : Overwrite existing ADR files without confirmation}
        {--fresh        : Delete all existing ADR files and the cache, then re-infer}
        {--refresh      : Bypass the cache and re-infer even if the manifest is unchanged}';

    protected $description = 'Infer app-level Architecture Decision Records from the Necromancer manifest using laravel/ai';

    public function handle(
        ManifestReader $reader,
        AiDetector $aiDetector,
        AdrInferrer $inferrer,
        AdrTranslator $translator,
        AdrCritic $critic,
        ManifestSummarizer $summarizer,
    ): int {
        $manifestPath = $this->resolveManifestPath();

        try {
            $manifest = $reader->read($manifestPath);
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        if (! $aiDetector->isAvailable()) {
            $this->error('laravel/ai is not installed.');
            $this->line('');
            $this->line('Run: composer require laravel/ai');
            $this->line('Then configure a provider in config/ai.php before running necromancer:infer.');

            return self::FAILURE;
        }

        $this->warnIfStale($manifest);

        $defaultLocale = app()->getLocale();
        $baseDir = (string) config('necromancer.inference.output.adrs', base_path('docs/adr/necromancer'));
        $extraLocales = $this->resolveExtraLocales($defaultLocale, $baseDir);
        $temperature = $this->option('temperature') !== null ? (float) $this->option('temperature') : null;
        $criticEnabled = (bool) config('necromancer.inference.critic.enabled', true);
        $criticRounds = max(1, (int) ($this->option('max-critic-rounds') ?? 1));
        $prompt = $summarizer->summarize($manifest);
        $provider = config('necromancer.inference.provider') ?: null;
        $model = config('necromancer.inference.model') ?: null;
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;

        $manifestContents = (string) file_get_contents($manifestPath);
        $contentHash = is_string($manifest['meta']['content_hash'] ?? null)
            ? $manifest['meta']['content_hash']
            : hash('sha256', $manifestContents);
        $cacheKey = $contentHash.':'.($temperature ?? 'default').':critic='.($criticEnabled ? '1' : '0').':rounds='.$criticRounds;
        $cache = new AdrInferenceCache($baseDir);

        if ($this->option('fresh')) {
            $cache->invalidate();
        }

        $useCache = ! $this->option('refresh') && ! $this->option('fresh');

        // Phase 1: infer canonical ADRs (from cache or AI)
        if ($useCache && $cache->hasCanonical($cacheKey)) {
            $canonical = $cache->getCanonical($cacheKey);
            $this->line('Using cached canonical ADRs (manifest unchanged).');
        } else {
            $raw = $inferrer->infer($prompt, $provider, $model, $defaultLocale, $temperature);
            $totalPromptTokens += $raw->promptTokens;
            $totalCompletionTokens += $raw->completionTokens;

            if ($criticEnabled && ! empty($raw->adrs)) {
                $currentAdrs = $raw->adrs;
                $criticPromptTokens = 0;
                $criticCompletionTokens = 0;

                for ($round = 0; $round < $criticRounds; $round++) {
                    if (empty($currentAdrs)) {
                        break;
                    }

                    $critiqued = $critic->critique($currentAdrs, $prompt, $provider, $model, $temperature);
                    $criticPromptTokens += $critiqued->promptTokens;
                    $criticCompletionTokens += $critiqued->completionTokens;
                    $currentAdrs = $critiqued->adrs;

                    if ($critiqued->satisfied) {
                        break;
                    }
                }

                $totalPromptTokens += $criticPromptTokens;
                $totalCompletionTokens += $criticCompletionTokens;
                $canonical = new AdrInferenceResult(
                    adrs: $currentAdrs,
                    promptTokens: $raw->promptTokens + $criticPromptTokens,
                    completionTokens: $raw->completionTokens + $criticCompletionTokens,
                );
            } else {
                $canonical = $raw;
            }

            $cache->setCanonical($cacheKey, $canonical);
        }

        if (empty($canonical->adrs)) {
            $this->line('No architectural decisions were inferred from the manifest.');

            return self::SUCCESS;
        }

        if (! $this->writeAdrs($canonical->adrs, $baseDir)) {
            return self::FAILURE;
        }

        // Phase 2: translate canonical into each extra locale
        foreach ($extraLocales as [$locale, $outputDir]) {
            if ($useCache && $cache->hasTranslation($cacheKey, $locale)) {
                $result = $cache->getTranslation($cacheKey, $locale);
                $this->line("Using cached translation for locale '{$locale}'.");
            } else {
                $result = $translator->translate($canonical->adrs, $locale, $provider, $model, $temperature);
                $cache->setTranslation($cacheKey, $locale, $result);
                $totalPromptTokens += $result->promptTokens;
                $totalCompletionTokens += $result->completionTokens;
            }

            if (! $this->writeAdrs($result->adrs, $outputDir, $locale)) {
                return self::FAILURE;
            }
        }

        if ($totalPromptTokens > 0 || $totalCompletionTokens > 0) {
            $totalTokens = $totalPromptTokens + $totalCompletionTokens;
            $this->line("Tokens used: {$totalTokens} (prompt: {$totalPromptTokens}, completion: {$totalCompletionTokens})");
        }

        return self::SUCCESS;
    }

    /** @param list<InferredAdr> $adrs */
    private function writeAdrs(array $adrs, string $outputDir, ?string $localeLabel = null): bool
    {
        if ($this->option('dry-run')) {
            if ($localeLabel !== null) {
                $this->line("=== Locale: {$localeLabel} ===");
            }

            foreach ($adrs as $adr) {
                $this->line("--- {$adr->title} ({$adr->slug}) ---");
                $this->line("Context: {$adr->context}");
                $this->line("Decision: {$adr->decision}");
                $this->line("Consequences: {$adr->consequences}");
                $this->line('');
            }

            return true;
        }

        if ($this->option('fresh') && is_dir($outputDir)) {
            foreach (glob($outputDir.'/[0-9][0-9][0-9][0-9]-*.md') ?: [] as $file) {
                unlink($file);
            }
        }

        $writer = new AdrWriter($outputDir);

        if (! $this->option('force') && ! $this->option('fresh')) {
            $existingSlugs = $this->existingSlugs($outputDir);

            foreach ($adrs as $adr) {
                if (in_array($adr->slug, $existingSlugs, true)) {
                    if (! $this->confirm("{$adr->slug} already exists. Overwrite?")) {
                        return false;
                    }
                }
            }
        }

        $paths = $writer->write($adrs, now()->toDateString());

        foreach ($paths as $path) {
            $this->line('✓  '.$path);
        }

        return true;
    }

    /** @return list<array{0: string, 1: string}> */
    private function resolveExtraLocales(string $defaultLocale, string $baseDir): array
    {
        $option = $this->option('locale');

        if (! filled($option)) {
            return [];
        }

        $locales = array_filter(array_map('trim', explode(',', $option)));

        return array_values(array_map(
            fn (string $locale) => [$locale, $baseDir.'/'.$locale],
            array_filter($locales, fn (string $locale) => $locale !== $defaultLocale),
        ));
    }

    /** @return list<string> */
    private function existingSlugs(string $outputDir): array
    {
        $slugs = [];

        foreach (glob($outputDir.'/[0-9][0-9][0-9][0-9]-*.md') ?: [] as $path) {
            $slugs[] = substr(basename($path, '.md'), 5);
        }

        return $slugs;
    }
}
