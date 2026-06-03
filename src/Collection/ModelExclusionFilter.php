<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Support\Str;
use LaravelNecromancer\Manifest\StructuralArtifact;

final readonly class ModelExclusionFilter
{
    /**
     * @param  list<string>  $patterns
     */
    public function __construct(private array $patterns = []) {}

    public function allows(StructuralArtifact $artifact): bool
    {
        if (! $artifact->isModel()) {
            return true;
        }

        $class = $artifact->modelClass();

        if ($class === null) {
            return true;
        }

        foreach ($this->patterns as $pattern) {
            if (Str::is($pattern, $class)) {
                return false;
            }
        }

        return true;
    }
}
