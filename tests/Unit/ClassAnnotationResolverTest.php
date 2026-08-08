<?php

use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Collection\AttributeReader;
use LaravelNecromancer\Metadata\ClassAnnotationResolver;
use LaravelNecromancer\Metadata\Risk;
use LaravelNecromancer\Tests\TestCase;
use PHPUnit\Framework\Assert;

uses(TestCase::class)->group('class-annotations');

// Fixture classes — defined at file level so ReflectionClass can find them.
#[Necromancer(domain: 'billing')]
class NecromancerAnnotatedParentFixture {}

class NecromancerUnannotatedChildFixture extends NecromancerAnnotatedParentFixture {}

test('resolve returns an empty ArtifactAnnotations when no attribute is present', function () {
    $result = (new ClassAnnotationResolver)->resolve(null);

    expect($result->isEmpty())->toBeTrue();
});

test('resolve converts a fully populated attribute into ArtifactAnnotations', function () {
    $attribute = new Necromancer(
        domain: 'billing',
        flow: 'subscription-cancellation',
        capability: 'subscription.cancel',
        summary: 'Cancels an active subscription.',
        risk: Risk::High,
        externalServices: ['stripe'],
        adrs: ['docs/adr/004-invoice-delivery.md'],
    );

    $result = (new ClassAnnotationResolver)->resolve($attribute);

    expect($result->domain)->toBe('billing')
        ->and($result->flow)->toBe('subscription-cancellation')
        ->and($result->capability)->toBe('subscription.cancel')
        ->and($result->summary)->toBe('Cancels an active subscription.')
        ->and($result->risk)->toBe(Risk::High)
        ->and($result->externalServices)->toBe(['stripe'])
        ->and($result->adrs)->toBe(['docs/adr/004-invoice-delivery.md']);
});

test('resolve trims outer whitespace from scalar fields', function () {
    $attribute = new Necromancer(domain: ' billing ', summary: '  Cancels.  ');

    $result = (new ClassAnnotationResolver)->resolve($attribute);

    expect($result->domain)->toBe('billing')
        ->and($result->summary)->toBe('Cancels.');
});

test('resolve trims and exactly dedupes list fields preserving first-occurrence order', function () {
    $attribute = new Necromancer(externalServices: [' stripe ', 'sendgrid', 'stripe']);

    $result = (new ClassAnnotationResolver)->resolve($attribute);

    expect($result->externalServices)->toBe(['stripe', 'sendgrid']);
});

test('resolve rejects an explicit empty scalar rather than treating it as absent', function () {
    $attribute = new Necromancer(domain: '   ');

    expect(fn () => (new ClassAnnotationResolver)->resolve($attribute))
        ->toThrow(InvalidArgumentException::class, 'domain');
});

test('resolve rejects a blank list item', function () {
    $attribute = new Necromancer(adrs: ['docs/adr/001.md', ' ']);

    expect(fn () => (new ClassAnnotationResolver)->resolve($attribute))
        ->toThrow(InvalidArgumentException::class, 'adrs');
});

test('resolve includes the declaring context in a scalar validation error so the failure is traceable', function () {
    $attribute = new Necromancer(domain: '   ');

    expect(fn () => (new ClassAnnotationResolver)->resolve($attribute, 'App\\Jobs\\SendInvoice'))
        ->toThrow(InvalidArgumentException::class, 'App\\Jobs\\SendInvoice');
});

test('resolve includes the declaring context in a list validation error so the failure is traceable', function () {
    $attribute = new Necromancer(adrs: [' ']);

    expect(fn () => (new ClassAnnotationResolver)->resolve($attribute, 'App\\Http\\Controllers\\BillingController::cancel'))
        ->toThrow(InvalidArgumentException::class, 'App\\Http\\Controllers\\BillingController::cancel');
});

test('resolve omits any context prefix when none is given', function () {
    $attribute = new Necromancer(domain: '   ');

    try {
        (new ClassAnnotationResolver)->resolve($attribute);
        Assert::fail('Expected an InvalidArgumentException.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->not->toContain(' on ');
    }
});

test('a parent class annotation is not inherited by a subclass', function () {
    $parentAttribute = AttributeReader::first(new ReflectionClass(NecromancerAnnotatedParentFixture::class), Necromancer::class);
    $childAttribute = AttributeReader::first(new ReflectionClass(NecromancerUnannotatedChildFixture::class), Necromancer::class);

    expect((new ClassAnnotationResolver)->resolve($parentAttribute)->domain)->toBe('billing')
        ->and($childAttribute)->toBeNull()
        ->and((new ClassAnnotationResolver)->resolve($childAttribute)->isEmpty())->toBeTrue();
});
