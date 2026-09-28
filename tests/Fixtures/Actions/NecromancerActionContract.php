<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Actions;

interface NecromancerActionContract
{
    public function handle(): void;
}
