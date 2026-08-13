<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands\Concerns;

trait ResolvesSkillPath
{
    /**
     * Maps a Boost skill directory to the `SKILL.md` file Boost's
     * SkillComposer expects to find inside it.
     */
    private function skillFilePath(string $skillDirectory): string
    {
        return rtrim($skillDirectory, '/\\').'/SKILL.md';
    }
}
