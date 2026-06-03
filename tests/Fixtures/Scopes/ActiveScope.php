<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class ActiveScope implements Scope
{
    public function apply(Builder $builder, Model $model): void {}
}
