<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Dispatches;

use Illuminate\Support\Facades\Event;

final class NecromancerDynamicDispatches
{
    public function handle(object $job, string $class): void
    {
        dispatch($job);
        event($this->make());
        dispatch(app($class));
        event('order.placed');
        Event::dispatch('order.placed', [1]);
        $this->dispatch('browser-event');
        dispatch(function (): void {});
        dispatch(new class {});
    }

    private function make(): object
    {
        return new \stdClass;
    }

    private function dispatch(string $event): void {}
}
