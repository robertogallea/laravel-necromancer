<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\DispatchApp\Actions;

use Illuminate\Support\Facades\Bus;
use LaravelNecromancer\Tests\Fixtures\DispatchApp\Jobs\ShipDispatchOrder;
use Vendor\Billing\ChargeCustomer;

final class PlaceDispatchOrder
{
    public function handle(): void
    {
        Bus::chain([new ChargeCustomer, new ShipDispatchOrder])->dispatch();
    }
}
