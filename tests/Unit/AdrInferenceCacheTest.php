<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use LaravelNecromancer\Inference\AdrInferenceCache;
use LaravelNecromancer\Inference\AdrInferenceResult;
use LaravelNecromancer\Inference\InferredAdr;

function makeCacheResult(string $title = 'ADR'): AdrInferenceResult
{
    return new AdrInferenceResult(
        adrs: [
            new InferredAdr($title, 'adr-slug', 'accepted', 'context', 'decision', 'consequences'),
        ],
        promptTokens: 10,
        completionTokens: 5,
    );
}

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir().'/necro-cache-'.uniqid();
    mkdir($this->cacheDir, 0755, true);
    $this->cache = new AdrInferenceCache($this->cacheDir);
    $this->key = 'test-key-abc';
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->cacheDir);
});

test('hasCanonical returns false when no cache file exists', function () {
    expect($this->cache->hasCanonical($this->key))->toBeFalse();
});

test('hasCanonical returns true after storing canonical result', function () {
    $this->cache->setCanonical($this->key, makeCacheResult());

    expect($this->cache->hasCanonical($this->key))->toBeTrue();
});

test('hasCanonical returns false for a different key', function () {
    $this->cache->setCanonical($this->key, makeCacheResult());

    expect($this->cache->hasCanonical('other-key'))->toBeFalse();
});

test('getCanonical returns the stored result', function () {
    $result = makeCacheResult('My ADR');
    $this->cache->setCanonical($this->key, $result);

    $cached = $this->cache->getCanonical($this->key);

    expect($cached)->not->toBeNull();
    expect($cached->adrs[0]->title)->toBe('My ADR');
    expect($cached->promptTokens)->toBe(10);
    expect($cached->completionTokens)->toBe(5);
});

test('hasTranslation returns false when translation has not been cached', function () {
    $this->cache->setCanonical($this->key, makeCacheResult());

    expect($this->cache->hasTranslation($this->key, 'it'))->toBeFalse();
});

test('hasTranslation returns true after storing a translation', function () {
    $this->cache->setCanonical($this->key, makeCacheResult());
    $this->cache->setTranslation($this->key, 'it', makeCacheResult('[it] ADR'));

    expect($this->cache->hasTranslation($this->key, 'it'))->toBeTrue();
});

test('getTranslation returns the stored translation', function () {
    $this->cache->setCanonical($this->key, makeCacheResult());
    $this->cache->setTranslation($this->key, 'it', makeCacheResult('[it] ADR'));

    $cached = $this->cache->getTranslation($this->key, 'it');

    expect($cached)->not->toBeNull();
    expect($cached->adrs[0]->title)->toBe('[it] ADR');
});

test('cache persists to disk and can be reloaded', function () {
    $this->cache->setCanonical($this->key, makeCacheResult('Persisted ADR'));

    $reloaded = new AdrInferenceCache($this->cacheDir);

    expect($reloaded->hasCanonical($this->key))->toBeTrue();
    expect($reloaded->getCanonical($this->key)->adrs[0]->title)->toBe('Persisted ADR');
});

test('invalidate clears canonical and all translations', function () {
    $this->cache->setCanonical($this->key, makeCacheResult());
    $this->cache->setTranslation($this->key, 'it', makeCacheResult('[it] ADR'));
    $this->cache->invalidate();

    expect($this->cache->hasCanonical($this->key))->toBeFalse();
    expect($this->cache->hasTranslation($this->key, 'it'))->toBeFalse();
});
