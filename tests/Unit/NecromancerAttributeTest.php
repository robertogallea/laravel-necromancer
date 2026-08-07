<?php

use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Metadata\Risk;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('class-annotations');

test('the attribute targets classes and methods only', function () {
    $reflection = new ReflectionClass(Necromancer::class);
    $instance = $reflection->getAttributes()[0]->newInstance();

    expect($instance->flags)->toBe(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD);
});

test('the attribute is not repeatable', function () {
    $reflection = new ReflectionClass(Necromancer::class);
    $instance = $reflection->getAttributes()[0]->newInstance();

    expect($instance->flags & Attribute::IS_REPEATABLE)->toBe(0);
});

test('every field is optional', function () {
    $attribute = new Necromancer;

    expect($attribute->domain)->toBeNull()
        ->and($attribute->flow)->toBeNull()
        ->and($attribute->capability)->toBeNull()
        ->and($attribute->summary)->toBeNull()
        ->and($attribute->risk)->toBeNull()
        ->and($attribute->externalServices)->toBe([])
        ->and($attribute->adrs)->toBe([]);
});

test('declaring the attribute twice on the same target is rejected by PHP', function () {
    expect(function () {
        eval(<<<'PHP'
            #[LaravelNecromancer\Attributes\Necromancer(domain: 'billing')]
            #[LaravelNecromancer\Attributes\Necromancer(domain: 'support')]
            final class NecromancerDoubleAnnotated {}
        PHP);

        $reflection = new ReflectionClass('NecromancerDoubleAnnotated');
        foreach ($reflection->getAttributes(Necromancer::class) as $attribute) {
            $attribute->newInstance();
        }
    })->toThrow(Error::class);
});

test('the risk argument accepts the Risk enum', function () {
    $attribute = new Necromancer(risk: Risk::Critical);

    expect($attribute->risk)->toBe(Risk::Critical);
});
