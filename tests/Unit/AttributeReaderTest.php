<?php

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use LaravelNecromancer\Collection\AttributeReader;

// Fixture classes — defined at file level so ReflectionClass can find them
#[ObservedBy('App\Observers\OrderObserver')]
#[ObservedBy(['App\Observers\AuditObserver', 'App\Observers\CacheObserver'])]
class MultiObserverModel {}

#[ScopedBy('App\Scopes\TenantScope')]
class ScopedModel
{
    public function handle(): void {}
}

class PlainModel {}

test('AttributeReader::first returns null when attribute is absent', function () {
    $reflection = new ReflectionClass(PlainModel::class);
    expect(AttributeReader::first($reflection, ObservedBy::class))->toBeNull();
});

test('AttributeReader::first returns the first attribute instance', function () {
    $reflection = new ReflectionClass(MultiObserverModel::class);
    $attr = AttributeReader::first($reflection, ObservedBy::class);
    expect($attr)->toBeInstanceOf(ObservedBy::class)
        ->and($attr->classes)->toBe('App\Observers\OrderObserver');
});

test('AttributeReader::all returns all instances of a repeatable attribute', function () {
    $reflection = new ReflectionClass(MultiObserverModel::class);
    $attrs = AttributeReader::all($reflection, ObservedBy::class);
    expect($attrs)->toHaveCount(2);
});

test('AttributeReader::all returns empty array when attribute is absent', function () {
    $reflection = new ReflectionClass(PlainModel::class);
    expect(AttributeReader::all($reflection, ObservedBy::class))->toBeEmpty();
});

test('AttributeReader::first works on ReflectionMethod', function () {
    $method = new ReflectionMethod(ScopedModel::class, 'handle');
    expect(AttributeReader::first($method, ScopedBy::class))->toBeNull();
});
