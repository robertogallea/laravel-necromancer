<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use LaravelNecromancer\Integrations\BoostDetector;

test('isAvailable returns true when the service provider class exists', function () {
    $detector = new BoostDetector(ServiceProvider::class);

    expect($detector->isAvailable())->toBeTrue();
});

test('isAvailable returns false when the service provider class does not exist', function () {
    $detector = new BoostDetector('NonExistent\\Vendor\\BoostServiceProvider');

    expect($detector->isAvailable())->toBeFalse();
});

test('the default service provider class checked is the Laravel Boost service provider', function () {
    $reflection = new ReflectionClass(BoostDetector::class);
    $default = $reflection->getConstructor()->getParameters()[0]->getDefaultValue();

    expect($default)->toBe('Laravel\\Boost\\BoostServiceProvider');
});
