# With Laravel Boost installed, Necromancer writes its own files and Boost composes the context

When Boost is detected, `necromancer:generate` writes its compact context to `boost.context_path` (`.ai/guidelines/necromancer.md`) and its full context as a skill directory at `boost.skill_path` (`.ai/skills/necromancer/SKILL.md`), instead of writing `CLAUDE.md`/`AGENTS.md` directly. Boost owns composing the agent files. Necromancer owns only its own files, and it prints `php artisan boost:update` for the user to run rather than running it. Two tools both writing `CLAUDE.md` would overwrite each other. With Boost present, this split avoids any conflict over that file.

## Consequences

- The user's agent files only change after `boost:update`. Running `necromancer:generate` alone doesn't change them.
- The skill output has to match what Boost's `SkillComposer` discovers: a directory containing `SKILL.md`, not a flat file.
- An explicit `--output` overrides the full-context path. Detection is automatic, so there is no `--boost`/`--no-boost` flag.
