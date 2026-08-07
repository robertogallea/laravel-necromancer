<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures;

use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Metadata\Risk;

#[Necromancer(domain: 'billing', risk: Risk::Low)]
final class NecromancerAnnotatedRouteController
{
    public function index(): string
    {
        return 'index';
    }

    #[Necromancer(capability: 'billing.charge')]
    public function charge(): string
    {
        return 'charge';
    }

    #[Necromancer(domain: 'support')]
    public function conflictingDomain(): string
    {
        return 'conflict';
    }
}
