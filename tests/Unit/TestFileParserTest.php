<?php

declare(strict_types=1);

use LaravelNecromancer\Collection\TestFileParser;

test('isPestFunctional returns true for a file with test() calls', function () {
    $parser = new TestFileParser(__DIR__.'/../Fixtures/Tests/NecromancerFunctionalTest.php');
    expect($parser->isPestFunctional())->toBeTrue();
});

test('isPestFunctional returns true for a file with it() calls', function () {
    $parser = new TestFileParser(__DIR__.'/../Fixtures/Tests/NecromancerFunctionalTest.php');
    expect($parser->isPestFunctional())->toBeTrue();
});

test('isPestFunctional returns false for a class-based test file', function () {
    $parser = new TestFileParser(__DIR__.'/../Fixtures/Tests/NecromancerClassBasedTest.php');
    expect($parser->isPestFunctional())->toBeFalse();
});

test('methods() extracts test and it call descriptions from a Pest functional file', function () {
    $parser = new TestFileParser(__DIR__.'/../Fixtures/Tests/NecromancerFunctionalTest.php');
    expect($parser->methods())->toContain('it creates an order', 'calculates the total');
});

test('methods() extracts public test_ method names from a class-based file', function () {
    $parser = new TestFileParser(__DIR__.'/../Fixtures/Tests/NecromancerClassBasedTest.php');
    $methods = $parser->methods();
    expect($methods)->toContain('test_it_creates_an_order', 'test_it_calculates_total');
});

test('declaredClass() returns null for a Pest functional file', function () {
    $parser = new TestFileParser(__DIR__.'/../Fixtures/Tests/NecromancerFunctionalTest.php');
    expect($parser->declaredClass())->toBeNull();
});

test('declaredClass() returns the FQCN for a class-based test file', function () {
    $parser = new TestFileParser(__DIR__.'/../Fixtures/Tests/NecromancerClassBasedTest.php');
    expect($parser->declaredClass())->toBe('LaravelNecromancer\\Tests\\Fixtures\\Tests\\NecromancerClassBasedTest');
});

test('usesSubject() returns the non-TestCase class from a uses() declaration', function () {
    $parser = new TestFileParser(__DIR__.'/../Fixtures/Tests/NecromancerUsesTest.php');
    expect($parser->usesSubject())->toBe('LaravelNecromancer\\Tests\\Fixtures\\Models\\NecromancerOrder');
});

test('usesSubject() returns null for a file with no uses() declaration', function () {
    $parser = new TestFileParser(__DIR__.'/../Fixtures/Tests/NecromancerFunctionalTest.php');
    expect($parser->usesSubject())->toBeNull();
});

test('usesSubject() returns null for a file that only uses TestCase', function () {
    $parser = new TestFileParser(__DIR__.'/../Fixtures/Tests/NecromancerClassBasedTest.php');
    expect($parser->usesSubject())->toBeNull();
});
