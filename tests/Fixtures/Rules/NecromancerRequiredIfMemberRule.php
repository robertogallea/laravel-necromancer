<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Rules;

use Closure;
use Illuminate\Contracts\Validation\ImplicitRule;
use Illuminate\Contracts\Validation\ValidationRule;

final class NecromancerRequiredIfMemberRule implements ImplicitRule, ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void {}

    public function passes($attribute, $value): bool
    {
        return true;
    }

    public function message(): string
    {
        return '';
    }
}
