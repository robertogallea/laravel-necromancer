<?php

declare(strict_types=1);

namespace LaravelNecromancer\Integrations;

final class BoostDetector
{
    public function __construct(
        private readonly string $serviceProviderClass = 'Laravel\\Boost\\BoostServiceProvider'
    ) {}

    public function isAvailable(): bool
    {
        return class_exists($this->serviceProviderClass);
    }
}
