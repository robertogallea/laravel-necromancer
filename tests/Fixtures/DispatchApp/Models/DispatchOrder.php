<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\DispatchApp\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelNecromancer\Tests\Fixtures\DispatchApp\Events\DispatchOrderPlaced;

final class DispatchOrder extends Model
{
    protected static function booted(): void
    {
        self::created(function (): void {
            event(new DispatchOrderPlaced);
        });
    }
}
