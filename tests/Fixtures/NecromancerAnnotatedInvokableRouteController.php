<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures;

use LaravelNecromancer\Attributes\Necromancer;

#[Necromancer(domain: 'billing')]
final class NecromancerAnnotatedInvokableRouteController
{
    #[Necromancer(capability: 'billing.process')]
    public function __invoke(): string
    {
        return 'necromancer annotated invokable route';
    }
}
