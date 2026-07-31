<?php

declare(strict_types=1);

namespace LaravelNecromancer\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelNecromancer\Metadata\RouteMetadataFactory;

/**
 * @method static array<string, array<string, mixed>> forMetadata(?string $domain = null, ?string $flow = null, ?string $capability = null, ?string $summary = null, ?string $risk = null, string|array|null $externalServices = null, ?string $adr = null)
 *
 * @see RouteMetadataFactory
 */
class Necromancer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RouteMetadataFactory::class;
    }
}
