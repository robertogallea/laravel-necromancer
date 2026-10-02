<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Http\Controllers;

final class NecromancerControllerSupport
{
    public static function format(string $value): string
    {
        return trim($value);
    }
}
