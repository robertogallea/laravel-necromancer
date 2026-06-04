<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use LaravelNecromancer\Integrations\AiDetector;

test('isAvailable returns true when the service provider class exists', function () {
    $detector = new AiDetector(ServiceProvider::class);

    expect($detector->isAvailable())->toBeTrue();
});

test('isAvailable returns false when the service provider class does not exist', function () {
    $detector = new AiDetector('NonExistent\\Ai\\AiServiceProvider');

    expect($detector->isAvailable())->toBeFalse();
});

test('the default class checked is the Laravel AI service provider', function () {
    $reflection = new ReflectionClass(AiDetector::class);
    $default = $reflection->getConstructor()->getParameters()[0]->getDefaultValue();

    expect($default)->toBe('Laravel\\Ai\\AiServiceProvider');
});
