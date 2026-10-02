<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Dispatches;

use LaravelNecromancer\Tests\Fixtures\Dispatches\Targets\ChargeCard;
use LaravelNecromancer\Tests\Fixtures\Dispatches\Targets\ChargeCard as Charge;

final class NecromancerNameForms
{
    public function imported(): void
    {
        ChargeCard::dispatch();
    }

    public function aliased(): void
    {
        dispatch(new Charge);
    }
}
