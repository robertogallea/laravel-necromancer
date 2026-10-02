<?php

use Illuminate\Support\Facades\Event;
use LaravelNecromancer\Benchmark\GoldenAnswerResolver;

/**
 * IssueOpened is listened to by NotifyWatchers (declared by the event) and
 * IssueClosed by CloseMilestone (declared by the listener).
 */
function listenedByManifest(): array
{
    return ['artifacts' => [
        'events' => [
            ['id' => 'events:App\\Events\\IssueOpened', 'class' => 'App\\Events\\IssueOpened', 'listeners' => ['App\\Listeners\\NotifyWatchers']],
            ['id' => 'events:App\\Events\\IssueClosed', 'class' => 'App\\Events\\IssueClosed'],
        ],
        'listeners' => [
            ['id' => 'listeners:App\\Listeners\\NotifyWatchers', 'class' => 'App\\Listeners\\NotifyWatchers', 'handles' => ['App\\Events\\IssueOpened']],
            ['id' => 'listeners:App\\Listeners\\CloseMilestone', 'class' => 'App\\Listeners\\CloseMilestone', 'handles' => ['App\\Events\\IssueClosed']],
        ],
    ]];
}

test('events.listeners is trusted when the event dispatcher registers every listened_by pair', function () {
    Event::listen('App\\Events\\IssueOpened', 'App\\Listeners\\NotifyWatchers@handle');
    Event::listen('App\\Events\\IssueClosed', ['App\\Listeners\\CloseMilestone', 'handle']);

    $result = (new GoldenAnswerResolver(listenedByManifest()))->resolve(['events.listeners']);

    expect($result['events.listeners'])->toBe(['value' => ['NotifyWatchers', 'CloseMilestone'], 'trusted' => true]);
});

test('events.listeners is untrusted when the event dispatcher does not register a listened_by pair', function () {
    Event::listen('App\\Events\\IssueOpened', 'App\\Listeners\\NotifyWatchers');
    Event::listen('App\\Events\\IssueOpened', 'App\\Listeners\\CloseMilestone');

    $result = (new GoldenAnswerResolver(listenedByManifest()))->resolve(['events.listeners']);

    expect($result['events.listeners']['trusted'])->toBeFalse();
});
