<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

final readonly class TestSubjectMatcher
{
    /**
     * Returns true when any subject exactly matches the class OR is a namespace prefix of it.
     * Prefix matching handles the case where a single test file covers an entire namespace
     * (e.g. ModelsTest.php with subject "App\Models" covers "App\Models\Order").
     *
     * @param  list<string>  $subjects
     */
    public static function matches(string $class, array $subjects): bool
    {
        if ($class === '') {
            return false;
        }

        foreach ($subjects as $subject) {
            if ($class === $subject || str_starts_with($class, $subject.'\\')) {
                return true;
            }
        }

        return false;
    }
}
