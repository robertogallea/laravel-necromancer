<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Dispatches;

use LaravelNecromancer\Tests\Fixtures\Dispatches\Targets\OrderPlaced;

trait NecromancerDispatchesShipping
{
    public function announce(): void
    {
        event(new OrderPlaced);
    }
}
