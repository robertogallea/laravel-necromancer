<?php

declare(strict_types=1);

use LaravelNecromancer\Collection\JobCollector;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('job-attributes');

function jobFixtureArtifact(string $class): ?array
{
    $collector = new JobCollector(
        app: app(),
        roots: [[
            'path' => __DIR__.'/../Fixtures/Jobs',
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Jobs\\',
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

test('JobCollector reads #[Queue] attribute when property is absent', function () {
    $data = jobFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Jobs\\JobWithQueueAttributes');
    expect($data['queue'])->toBe('notifications');
});

test('JobCollector reads #[Connection] attribute when property is absent', function () {
    $data = jobFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Jobs\\JobWithQueueAttributes');
    expect($data['connection'])->toBe('redis');
});

test('JobCollector reads #[Tries] attribute when property is absent', function () {
    $data = jobFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Jobs\\JobWithQueueAttributes');
    expect($data['tries'])->toBe(5);
});

test('JobCollector reads #[Timeout] attribute when property is absent', function () {
    $data = jobFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Jobs\\JobWithQueueAttributes');
    expect($data['timeout'])->toBe(120);
});

test('JobCollector reads #[Backoff] attribute into backoff field', function () {
    $data = jobFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Jobs\\JobWithQueueAttributes');
    expect($data['backoff'])->toBe([30, 60, 90]);
});

test('JobCollector reads #[MaxExceptions] attribute into max_exceptions field', function () {
    $data = jobFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Jobs\\JobWithQueueAttributes');
    expect($data['max_exceptions'])->toBe(3);
});

test('JobCollector still reads queue from property when attribute is absent', function () {
    $data = jobFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Jobs\\JobWithProperties');
    expect($data['queue'])->toBe('emails')
        ->and($data['tries'])->toBe(3);
});
