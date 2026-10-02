<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\DispatchApp\Http\Controllers;

use LaravelNecromancer\Tests\Fixtures\DispatchApp\Jobs\ShipDispatchOrder;

final class DispatchOrderController
{
    public function store(): void
    {
        ShipDispatchOrder::dispatch();
    }
}
