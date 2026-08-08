<?php

use LaravelNecromancer\Okf\Enrichment\EnrichmentCache;
use LaravelNecromancer\Okf\Enrichment\RawEnrichment;

function enrichmentCacheDir(): string
{
    $dir = sys_get_temp_dir().'/necromancer-enrichment-cache-'.uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function removeEnrichmentCacheTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/necromancer-enrichment-cache-*') as $dir) {
        removeEnrichmentCacheTree($dir);
    }
});

test('has() is false for a concept never cached', function () {
    $cache = new EnrichmentCache(enrichmentCacheDir());

    expect($cache->has('jobs:App\\Jobs\\X', 'key-1'))->toBeFalse();
});

test('set() then has()/get() round-trips a result for the same key', function () {
    $dir = enrichmentCacheDir();
    $cache = new EnrichmentCache($dir);
    $result = new RawEnrichment('A job.', 'It sends invoices.', 10, 5);

    $cache->set('jobs:App\\Jobs\\X', 'key-1', $result);

    expect($cache->has('jobs:App\\Jobs\\X', 'key-1'))->toBeTrue();

    $fetched = $cache->get('jobs:App\\Jobs\\X', 'key-1');
    expect($fetched->description)->toBe('A job.')
        ->and($fetched->narrative)->toBe('It sends invoices.')
        ->and($fetched->promptTokens)->toBe(10)
        ->and($fetched->completionTokens)->toBe(5);
});

test('has() is false when the key changed for the same concept', function () {
    $dir = enrichmentCacheDir();
    $cache = new EnrichmentCache($dir);
    $cache->set('jobs:App\\Jobs\\X', 'key-1', new RawEnrichment('A job.', 'Text.', 10, 5));

    expect($cache->has('jobs:App\\Jobs\\X', 'key-2'))->toBeFalse();
});

test('a cached result survives across cache instances (persisted to disk)', function () {
    $dir = enrichmentCacheDir();
    (new EnrichmentCache($dir))->set('jobs:App\\Jobs\\X', 'key-1', new RawEnrichment('A job.', 'Text.', 10, 5));

    $reloaded = new EnrichmentCache($dir);

    expect($reloaded->has('jobs:App\\Jobs\\X', 'key-1'))->toBeTrue();
});

test('caching multiple concepts keeps them independently addressable', function () {
    $dir = enrichmentCacheDir();
    $cache = new EnrichmentCache($dir);
    $cache->set('jobs:App\\Jobs\\X', 'key-1', new RawEnrichment('Job.', 'Job text.', 1, 1));
    $cache->set('routes:GET:orders', 'key-1', new RawEnrichment('Route.', 'Route text.', 2, 2));

    expect($cache->get('jobs:App\\Jobs\\X', 'key-1')->description)->toBe('Job.')
        ->and($cache->get('routes:GET:orders', 'key-1')->description)->toBe('Route.');
});

test('invalidate() clears every cached concept', function () {
    $dir = enrichmentCacheDir();
    $cache = new EnrichmentCache($dir);
    $cache->set('jobs:App\\Jobs\\X', 'key-1', new RawEnrichment('Job.', 'Text.', 1, 1));

    $cache->invalidate();

    expect($cache->has('jobs:App\\Jobs\\X', 'key-1'))->toBeFalse();
});
