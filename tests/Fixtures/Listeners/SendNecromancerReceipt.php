<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use LaravelNecromancer\Tests\Fixtures\Events\NecromancerOrderPlaced;
use LogicException;

final class SendNecromancerReceipt implements ShouldQueue
{
    public function __construct(private readonly string $mailer) {}

    public function handle(NecromancerOrderPlaced $event): void
    {
        throw new LogicException('Fixture listeners must not run during scans.');
    }
}
