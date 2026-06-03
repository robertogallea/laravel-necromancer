<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Support\Str;
use LaravelNecromancer\Manifest\StructuralArtifact;

final readonly class RouteNoiseFilter
{
    /**
     * @param  list<string>  $patterns
     */
    public function __construct(
        private array $patterns = ['horizon.*', 'telescope.*', 'debugbar.*'],
    ) {}

    public function allows(StructuralArtifact $artifact): bool
    {
        if (! $artifact->isRoute()) {
            return true;
        }

        $name = $artifact->routeName();

        if ($name === null) {
            return true;
        }

        foreach ($this->patterns as $pattern) {
            if (Str::is($pattern, $name)) {
                return false;
            }
        }

        return true;
    }
}
