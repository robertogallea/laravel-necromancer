<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Http\Concerns;

trait NecromancerRespondsWithJson
{
    public function respond(string $payload): string
    {
        return $payload;
    }

    public function export(): string
    {
        return 'export';
    }
}
