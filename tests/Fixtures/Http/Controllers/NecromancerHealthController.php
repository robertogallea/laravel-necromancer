<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Http\Controllers;

final class NecromancerHealthController
{
    public function __invoke(): string
    {
        return 'ok';
    }
}
