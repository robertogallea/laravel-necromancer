<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class NecromancerUnguardedModel extends Model
{
    protected $table = 'necromancer_unguarded';

    protected $guarded = [];
}
