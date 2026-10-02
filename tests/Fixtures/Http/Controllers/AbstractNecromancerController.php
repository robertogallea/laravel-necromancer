<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Http\Controllers;

abstract class AbstractNecromancerController
{
    public function store(): string
    {
        return 'store';
    }

    public function destroy(): string
    {
        return 'destroy';
    }
}
