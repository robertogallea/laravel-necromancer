<?php

declare(strict_types=1);

use LaravelNecromancer\Collection\FormRequestCollector;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class);

function formRequestFixtureArtifact(string $class): ?array
{
    $collector = new FormRequestCollector(
        app: app(),
        roots: [[
            'path' => __DIR__.'/../Fixtures/Requests',
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Requests\\',
        ]],
    );

    foreach ($collector->collect() as $artifact) {
        $data = $artifact->jsonSerialize();
        if ($data['class'] === $class) {
            return $data;
        }
    }

    return null;
}

test('FormRequestCollector reads #[StopOnFirstFailure] into stop_on_first_failure field', function () {
    $data = formRequestFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Requests\\RequestWithValidationAttributes');
    expect($data['stop_on_first_failure'])->toBeTrue();
});

test('FormRequestCollector reads #[ErrorBag] into error_bag field', function () {
    $data = formRequestFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Requests\\RequestWithValidationAttributes');
    expect($data['error_bag'])->toBe('orderForm');
});

test('stop_on_first_failure and error_bag are absent when attributes not present', function () {
    $artifact = StructuralArtifact::formRequest(
        class: 'App\\Http\\Requests\\StoreOrderRequest',
        rules: ['name' => 'required'],
    );
    $data = $artifact->jsonSerialize();
    expect($data)->not->toHaveKey('stop_on_first_failure')
        ->and($data)->not->toHaveKey('error_bag');
});
