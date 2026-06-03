<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures;

final class NecromancerInvokableRouteController
{
    public function __invoke(): string
    {
        return 'necromancer invokable route';
    }
}
