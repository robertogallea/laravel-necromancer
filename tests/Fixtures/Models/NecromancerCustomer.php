<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

final class NecromancerCustomer extends Model
{
    protected $table = 'necromancer_customers';

    protected $fillable = [
        'name',
        'email',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(NecromancerOrder::class, 'customer_id');
    }

    public function displayName(): string
    {
        return 'Customer';
    }

    public function requiresArgument(string $status): HasMany
    {
        return $this->hasMany(NecromancerOrder::class, $status);
    }

    public function throwingRelationship(): HasMany
    {
        throw new RuntimeException('This relationship should be skipped.');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'onboarded_at' => 'datetime',
        ];
    }
}
