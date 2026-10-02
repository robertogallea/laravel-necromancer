<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\DispatchApp\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use LaravelNecromancer\Tests\Fixtures\DispatchApp\Events\DispatchOrderPlaced;
use LaravelNecromancer\Tests\Fixtures\DispatchApp\Mail\DispatchOrderShipped;

final class ShipDispatchOrder implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        event(new DispatchOrderPlaced);
        Mail::to('customer@example.com')->queue(new DispatchOrderShipped);
    }
}
