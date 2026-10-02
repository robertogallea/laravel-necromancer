<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Dispatches;

use LaravelNecromancer\Tests\Fixtures\Dispatches\Targets\ShipOrder;

abstract class NecromancerBaseOperation
{
    public function afterwards(): void
    {
        ShipOrder::dispatch();
    }
}
