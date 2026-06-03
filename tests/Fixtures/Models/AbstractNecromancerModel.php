<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractNecromancerModel extends Model
{
    protected $table = 'abstract_necromancer_models';
}
