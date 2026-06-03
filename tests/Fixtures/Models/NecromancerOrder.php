<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NecromancerOrder extends Model
{
    protected $table = 'necromancer_orders';

    protected $fillable = [
        'customer_id',
        'total',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(NecromancerCustomer::class, 'customer_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
        ];
    }
}
