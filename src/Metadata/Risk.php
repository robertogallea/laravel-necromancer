<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

enum Risk: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
