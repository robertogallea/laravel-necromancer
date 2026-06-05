<?php

declare(strict_types=1);

use LaravelNecromancer\Collection\LivewireCollector;
use LaravelNecromancer\Tests\Fixtures\Livewire\NecromancerMultiListenForm;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('livewire-collector');

test('inferViewName produces dot-separated segments for nested namespace components', function () {
    // Override app namespace to match the fixture root so inferViewName strips correctly.
    // The collector will strip `{appNamespace}Livewire\` from the class name.
    // We use the fixture namespace as the app namespace and pass a sub-namespace root.
    $app = app();

    (function (): void {
        $this->namespace = 'LaravelNecromancer\\Tests\\Fixtures\\Livewire\\';
    })->call($app);

    // Provide a class whose FQCN starts with `{appNamespace}Livewire\Forms\...`
    // so shortName becomes "Forms\NecromancerContactForm" after stripping.
    // We register it inline via a custom root narrowed to the Forms sub-directory.
    $collector = new LivewireCollector($app, [[
        'path' => base_path('tests/Fixtures/Livewire/Forms'),
        'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Livewire\\Livewire\\Forms\\',
    ]]);

    // Use Reflection to call inferViewName directly with a controlled class string.
    $method = new ReflectionMethod(LivewireCollector::class, 'inferViewName');

    // Simulate: appNamespace = 'LaravelNecromancer\Tests\Fixtures\Livewire\'
    // class = 'LaravelNecromancer\Tests\Fixtures\Livewire\Livewire\Forms\ContactForm'
    // stripped shortName = 'Forms\ContactForm'
    $fakeClass = 'LaravelNecromancer\\Tests\\Fixtures\\Livewire\\Livewire\\Forms\\ContactForm';
    $result = $method->invoke($collector, $fakeClass);

    expect($result)->toBe('livewire.forms.contact-form');
});

test('inferViewName produces correct kebab view name for top-level components', function () {
    $app = app();

    (function (): void {
        $this->namespace = 'LaravelNecromancer\\Tests\\Fixtures\\Livewire\\';
    })->call($app);

    $collector = new LivewireCollector($app, []);

    $method = new ReflectionMethod(LivewireCollector::class, 'inferViewName');

    // shortName after stripping prefix = 'IssueForm'
    $fakeClass = 'LaravelNecromancer\\Tests\\Fixtures\\Livewire\\Livewire\\IssueForm';
    $result = $method->invoke($collector, $fakeClass);

    expect($result)->toBe('livewire.issue-form');
});

test('collectListens expands array-style #[On] attributes into individual string entries', function () {
    $app = app();

    (function (): void {
        $this->namespace = 'LaravelNecromancer\\Tests\\Fixtures\\Livewire\\';
    })->call($app);

    $collector = new LivewireCollector($app, [[
        'path' => base_path('tests/Fixtures/Livewire'),
        'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Livewire\\',
    ]]);

    $artifacts = $collector->collect();

    $multiListen = collect($artifacts)
        ->first(fn ($a) => $a->jsonSerialize()['class'] === NecromancerMultiListenForm::class);

    expect($multiListen)->not->toBeNull();

    $listens = $multiListen->jsonSerialize()['listens'];

    expect($listens)->toContain('event-a')
        ->and($listens)->toContain('event-b')
        ->and($listens)->each->toBeString();
});
