<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Appends(['formatted_total'])]
#[ObservedBy('LaravelNecromancer\Tests\Fixtures\Observers\OrderObserver')]
#[ScopedBy('LaravelNecromancer\Tests\Fixtures\Scopes\ActiveScope')]
#[UsePolicy('LaravelNecromancer\Tests\Fixtures\Policies\OrderPolicy')]
#[UseFactory('LaravelNecromancer\Tests\Fixtures\Factories\OrderFactory')]
#[UseEloquentBuilder('LaravelNecromancer\Tests\Fixtures\Builders\OrderBuilder')]
class OrderWithAttributes extends Model
{
    protected $table = 'orders';

    public function scopeVerified(Builder $query): void {}

    #[Scope]
    public function pending(Builder $query): void {}
}
