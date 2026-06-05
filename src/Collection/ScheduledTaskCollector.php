<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use LaravelNecromancer\Manifest\StructuralArtifact;

final readonly class ScheduledTaskCollector
{
    public function __construct(private Application $app) {}

    /**
     * @return list<StructuralArtifact>
     */
    public function collect(): array
    {
        $schedule = $this->app->make(Schedule::class);
        $artifacts = [];

        foreach ($schedule->events() as $event) {
            $artifacts[] = $this->fromEvent($event);
        }

        return $artifacts;
    }

    private function fromEvent(Event $event): StructuralArtifact
    {
        $command = $this->resolveCommand($event);
        $expression = $event->expression;
        $humanReadable = $event->getSummaryForDisplay();

        // getSummaryForDisplay() returns description when set, otherwise the raw built command.
        // We want the expression-based human readable when there's no description.
        if ($humanReadable === $event->command || str_starts_with($humanReadable, "'/")) {
            $humanReadable = $this->humanReadableExpression($expression);
        }

        $appTimezone = (string) config('app.timezone', 'UTC');
        $eventTimezone = $event->timezone !== null ? (string) $event->timezone : null;
        $timezone = ($eventTimezone === null || $eventTimezone === $appTimezone) ? null : $eventTimezone;

        return StructuralArtifact::scheduledTask(
            command: $command,
            expression: $expression,
            humanReadable: $humanReadable,
            withoutOverlapping: $event->withoutOverlapping,
            runInBackground: $event->runInBackground,
            evenInMaintenance: $event->evenInMaintenanceMode,
            timezone: $timezone,
            description: $event->description,
            source: null,
        );
    }

    private function resolveCommand(Event $event): string
    {
        if ($event instanceof CallbackEvent) {
            return 'Closure';
        }

        $raw = (string) ($event->command ?? '');

        // Artisan commands are formatted as: '/php-binary' 'artisan' command-name [options]
        // We strip the binary prefix and keep only the artisan command name.
        if (preg_match("/['\"]?artisan['\"]?\s+(.+)$/", $raw, $matches)) {
            // Extract just the command name (first token before any space/option)
            $rest = trim($matches[1]);
            // Remove any redirects (> /dev/null etc.) that may have been appended
            $tokens = preg_split('/\s+/', $rest);

            return $tokens !== false && $tokens[0] !== '' ? $tokens[0] : $raw;
        }

        return $raw;
    }

    private function humanReadableExpression(string $expression): string
    {
        $map = [
            '* * * * *' => 'Every minute',
            '*/5 * * * *' => 'Every 5 minutes',
            '*/10 * * * *' => 'Every 10 minutes',
            '*/15 * * * *' => 'Every 15 minutes',
            '*/30 * * * *' => 'Every 30 minutes',
            '0 * * * *' => 'Hourly',
            '0 0 * * *' => 'Daily',
            '0 0 * * 0' => 'Weekly',
            '0 0 1 * *' => 'Monthly',
            '0 0 1 1 *' => 'Yearly',
        ];

        return $map[$expression] ?? $expression;
    }
}
