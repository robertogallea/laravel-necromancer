<?php

declare(strict_types=1);

namespace LaravelNecromancer\Integrations;

final class AiDetector
{
    public function __construct(
        private readonly string $serviceProviderClass = 'Laravel\\Ai\\AiServiceProvider'
    ) {}

    public function isAvailable(): bool
    {
        return class_exists($this->serviceProviderClass);
    }
}
