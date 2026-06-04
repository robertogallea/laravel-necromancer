<?php

declare(strict_types=1);

use LaravelNecromancer\Collection\TestCollector;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('test-collector');

function testFixtureArtifact(string $filename): ?array
{
    $collector = new TestCollector(
        app: app(),
        roots: [[
            'path' => __DIR__.'/../Fixtures/Tests',
            'type' => 'unit',
        ]],
    );

    foreach ($collector->collect() as $artifact) {
        $data = $artifact->jsonSerialize();
        if (str_ends_with((string) ($data['file'] ?? ''), $filename)) {
            return $data;
        }
    }

    return null;
}

test('TestCollector returns a StructuralArtifact for each PHP file in the roots', function () {
    $collector = new TestCollector(
        app: app(),
        roots: [[
            'path' => __DIR__.'/../Fixtures/Tests',
            'type' => 'unit',
        ]],
    );

    expect($collector->collect())->toHaveCount(3);
});

test('TestCollector returns empty array when the directory does not exist', function () {
    $collector = new TestCollector(
        app: app(),
        roots: [[
            'path' => __DIR__.'/../Fixtures/NonExistentTests',
            'type' => 'unit',
        ]],
    );

    expect($collector->collect())->toBeEmpty();
});

test('functional fixture produces an artifact with type=unit and no class', function () {
    $data = testFixtureArtifact('NecromancerFunctionalTest.php');
    expect($data)->not->toBeNull()
        ->and($data['type'])->toBe('unit')
        ->and($data)->not->toHaveKey('class');
});

test('functional fixture produces methods with test() and it() descriptions', function () {
    $data = testFixtureArtifact('NecromancerFunctionalTest.php');
    expect($data['methods'])->toContain('it creates an order', 'calculates the total');
});

test('class-based fixture produces an artifact with the FQCN class', function () {
    $data = testFixtureArtifact('NecromancerClassBasedTest.php');
    expect($data['class'])->toBe('LaravelNecromancer\\Tests\\Fixtures\\Tests\\NecromancerClassBasedTest');
});

test('class-based fixture produces methods with the test method names', function () {
    $data = testFixtureArtifact('NecromancerClassBasedTest.php');
    expect($data['methods'])->toContain('test_it_creates_an_order', 'test_it_calculates_total');
});

test('uses() fixture infers the subject from the uses() declaration', function () {
    $data = testFixtureArtifact('NecromancerUsesTest.php');
    expect($data['subject'])->toBe('LaravelNecromancer\\Tests\\Fixtures\\Models\\NecromancerOrder');
});

test('each artifact has a source with file, line, line_end, and hash', function () {
    $data = testFixtureArtifact('NecromancerFunctionalTest.php');
    expect($data['source'])->toHaveKeys(['file', 'line', 'line_end', 'hash'])
        ->and($data['source']['line'])->toBe(1)
        ->and($data['source']['line_end'])->toBeInt()
        ->and($data['source']['hash'])->toBeString();
});
