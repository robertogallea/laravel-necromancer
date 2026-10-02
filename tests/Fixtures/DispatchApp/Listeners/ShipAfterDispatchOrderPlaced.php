<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\DispatchApp\Listeners;

use LaravelNecromancer\Tests\Fixtures\DispatchApp\Events\DispatchOrderPlaced;
use LaravelNecromancer\Tests\Fixtures\DispatchApp\Jobs\ShipDispatchOrder;

final class ShipAfterDispatchOrderPlaced
{
    public function handle(DispatchOrderPlaced $event): void
    {
        dispatch_sync(new ShipDispatchOrder);
    }
}
