<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Dispatches;

final class NecromancerInheritingOperation extends NecromancerBaseOperation
{
    use NecromancerDispatchesShipping;

    public function handle(): void {}
}
