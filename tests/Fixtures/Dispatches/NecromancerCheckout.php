<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Dispatches;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use LaravelNecromancer\Tests\Fixtures\Dispatches\Targets\ChargeCard;
use LaravelNecromancer\Tests\Fixtures\Dispatches\Targets\OrderPlaced;
use LaravelNecromancer\Tests\Fixtures\Dispatches\Targets\OrderReceipt;
use LaravelNecromancer\Tests\Fixtures\Dispatches\Targets\ShipOrder;

/**
 * One method per row of the dispatch call-shape table. Never loaded: the
 * targets don't exist, the file is only read as source text.
 */
final class NecromancerCheckout
{
    public function queuedHelper(): void
    {
        dispatch(new ChargeCard);
    }

    public function queuedStatic(): void
    {
        ChargeCard::dispatch(42);
        ShipOrder::dispatchIf(true, 42);
        OrderPlaced::dispatchUnless(false);
    }

    public function queuedBus(): void
    {
        Bus::dispatch(new ChargeCard);
        $this->dispatch(new ShipOrder);
    }

    public function syncHelper(): void
    {
        dispatch_sync(new ChargeCard);
        ShipOrder::dispatchSync();
        Bus::dispatchSync(new OrderPlaced);
    }

    public function chained(): void
    {
        Bus::chain([new ChargeCard, new ShipOrder])->dispatch();
        Bus::batch([new ShipOrder])->dispatch();
    }

    public function events(): void
    {
        event(new OrderPlaced);
        Event::dispatch(new ChargeCard);
        broadcast(new ShipOrder);
    }

    public function mailSync(): void
    {
        Mail::send(new OrderReceipt);
        Mail::to('a@example.com')->send(new ChargeCard);
    }

    public function mailQueued(): void
    {
        Mail::to('a@example.com')->queue(new OrderReceipt);
        Mail::to('a@example.com')->cc('b@example.com')->later(60, new ShipOrder);
    }

    private function dispatch(object $job): void {}
}
