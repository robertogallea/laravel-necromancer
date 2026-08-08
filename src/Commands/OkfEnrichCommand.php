<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Integrations\AiDetector;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Okf\Enrichment\BundleEnricher;
use LaravelNecromancer\Okf\Enrichment\Contracts\ConceptEnricher;
use LaravelNecromancer\Okf\Enrichment\EnrichmentCache;

final class OkfEnrichCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:okf-enrich
        {--output=       : Override the enriched bundle output directory}
        {--allow-stale   : Enrich even if the manifest appears stale}
        {--allow-partial : Enrich even if the manifest scope is partial}
        {--provider=     : AI provider override}
        {--model=        : AI model override}
        {--temperature=  : LLM temperature (0.0–2.0). Omit to use the provider default}
        {--refresh       : Bypass the enrichment cache and re-enrich every concept}';

    protected $description = 'Generate an enriched sibling OKF Knowledge Bundle using AI-generated prose';

    public function handle(ManifestReader $reader, ConceptEnricher $enricher, AiDetector $aiDetector, BundleEnricher $bundleEnricher): int
    {
        $path = $this->resolveManifestPath();

        try {
            $manifest = $reader->read($path);
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        if (! $aiDetector->isAvailable()) {
            $this->error('laravel/ai is not installed.');
            $this->line('');
            $this->line('Run: composer require laravel/ai');
            $this->line('Then configure a provider in config/ai.php before running necromancer:okf-enrich.');

            return self::FAILURE;
        }

        $temperature = $this->option('temperature') !== null ? (float) $this->option('temperature') : null;
        $provider = $this->option('provider') ?: (config('necromancer.okf.enrichment.provider') ?: null);
        $model = $this->option('model') ?: (config('necromancer.okf.enrichment.model') ?: null);

        $result = $bundleEnricher->enrich(
            manifest: $manifest,
            enricher: $enricher,
            cache: new EnrichmentCache((string) config('necromancer.okf.enrichment.cache', storage_path('app/necromancer/okf-enrichment-cache'))),
            outputPath: $this->resolveOutputPath(),
            basePath: base_path(),
            stale: $this->isStale($manifest),
            allowStale: (bool) $this->option('allow-stale'),
            allowPartial: (bool) $this->option('allow-partial'),
            privacyPolicy: (string) config('necromancer.okf.enrichment.privacy_policy', 'excludes-source-framework-config-adr-bodies'),
            promptVersion: (string) config('necromancer.okf.enrichment.prompt_version', '1'),
            provider: $provider,
            model: $model,
            temperature: $temperature,
            refresh: (bool) $this->option('refresh'),
        );

        if (! $result->successful) {
            $this->error($result->error ?? 'OKF enrichment failed.');

            return self::FAILURE;
        }

        $this->info("Enriched {$result->conceptCount} concept(s) ({$result->freshCount} generated, {$result->cachedCount} cached) to {$result->outputPath}.");

        return self::SUCCESS;
    }

    private function resolveOutputPath(): string
    {
        $override = $this->option('output');

        $path = is_string($override) && $override !== ''
            ? $override
            : (string) config('necromancer.okf.enrichment.output', base_path('okf-enriched'));

        return $this->isAbsolutePath($path) ? $path : base_path($path);
    }
}
