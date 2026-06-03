<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Enums;

enum NecromancerStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Pending = 'pending';
}
