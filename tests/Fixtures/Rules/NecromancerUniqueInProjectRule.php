<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validates that a value is unique within a project. */
final class NecromancerUniqueInProjectRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void {}
}
