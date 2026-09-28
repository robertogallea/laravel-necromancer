<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Actions;

enum NecromancerActionOutcome: string
{
    case Done = 'done';

    public function label(): string
    {
        return 'Done';
    }
}
