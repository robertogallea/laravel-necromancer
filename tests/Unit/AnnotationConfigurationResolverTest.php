<?php

use LaravelNecromancer\Metadata\AnnotationConfigurationResolver;
use LaravelNecromancer\Metadata\Risk;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('class-annotations');

test('a valid mapping resolves into an ArtifactAnnotations value object', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:App\\Jobs\\SendInvoice' => [
            'domain' => 'billing',
            'capability' => 'invoice.send',
            'risk' => 'high',
            'external_services' => ['stripe'],
            'adrs' => ['docs/adr/001.md'],
        ],
    ]);

    $mappings = $resolver->mappings();

    expect($mappings)->toHaveKey('jobs:App\\Jobs\\SendInvoice');

    $annotations = $mappings['jobs:App\\Jobs\\SendInvoice'];

    expect($annotations->domain)->toBe('billing')
        ->and($annotations->capability)->toBe('invoice.send')
        ->and($annotations->risk)->toBe(Risk::High)
        ->and($annotations->externalServices)->toBe(['stripe'])
        ->and($annotations->adrs)->toBe(['docs/adr/001.md']);
});

test('null fields and empty lists mean absent and are omitted without error', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:App\\Jobs\\SendInvoice' => [
            'domain' => 'billing',
            'flow' => null,
            'external_services' => [],
        ],
    ]);

    $annotations = $resolver->mappings()['jobs:App\\Jobs\\SendInvoice'];

    expect($annotations->domain)->toBe('billing')
        ->and($annotations->flow)->toBeNull()
        ->and($annotations->externalServices)->toBe([]);
});

test('an unknown field in a mapping is a fatal AN_SCHEMA_UNKNOWN_FIELD error', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:App\\Jobs\\SendInvoice' => ['owner' => 'platform-team'],
    ]);

    expect(fn () => $resolver->mappings())
        ->toThrow(InvalidArgumentException::class, 'AN_SCHEMA_UNKNOWN_FIELD');
});

test('an empty scalar value in a mapping is a fatal AN_SCHEMA_INVALID_VALUE error', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:App\\Jobs\\SendInvoice' => ['domain' => '   '],
    ]);

    expect(fn () => $resolver->mappings())
        ->toThrow(InvalidArgumentException::class, 'AN_SCHEMA_INVALID_VALUE');
});

test('an invalid risk value in a mapping is a fatal AN_SCHEMA_INVALID_VALUE error', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:App\\Jobs\\SendInvoice' => ['risk' => 'urgent'],
    ]);

    expect(fn () => $resolver->mappings())
        ->toThrow(InvalidArgumentException::class, 'AN_SCHEMA_INVALID_VALUE');
});

test('a non-string or empty list item in a mapping is a fatal AN_SCHEMA_INVALID_VALUE error', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:App\\Jobs\\SendInvoice' => ['external_services' => ['stripe', '']],
    ]);

    expect(fn () => $resolver->mappings())
        ->toThrow(InvalidArgumentException::class, 'AN_SCHEMA_INVALID_VALUE');
});

test('a wildcard mapping key is rejected as invalid', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:App\\Jobs\\*' => ['domain' => 'billing'],
    ]);

    expect(fn () => $resolver->mappings())
        ->toThrow(InvalidArgumentException::class);
});

test('a mapping key without a known artifact-type prefix is rejected as invalid', function () {
    $resolver = new AnnotationConfigurationResolver([
        'widgets:App\\Widgets\\Foo' => ['domain' => 'billing'],
    ]);

    expect(fn () => $resolver->mappings())
        ->toThrow(InvalidArgumentException::class);
});

test('a mapping key missing its identity component is rejected as invalid', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:' => ['domain' => 'billing'],
    ]);

    expect(fn () => $resolver->mappings())
        ->toThrow(InvalidArgumentException::class);
});

test('a mapping key with a known type prefix but the wrong canonical shape is rejected as invalid', function (string $id) {
    $resolver = new AnnotationConfigurationResolver([
        $id => ['domain' => 'billing'],
    ]);

    expect(fn () => $resolver->mappings())
        ->toThrow(InvalidArgumentException::class, 'AN_SCHEMA_INVALID_VALUE');
})->with([
    'middleware with an unrecognized scope' => 'middleware:bogus-scope:App\\Http\\Middleware\\Foo',
    'middleware alias missing its identity' => 'middleware:alias:',
    'gates with an unrecognized kind' => 'gates:not-a-kind:x',
    'gates hook with a non-numeric index' => 'gates:before_hook:first',
    'routes with a lowercase method' => 'routes:get:billing/cancel',
    'routes missing the URI segment' => 'routes:GET',
    'scheduled_tasks with a non-hex digest' => 'scheduled_tasks:not-a-digest:1',
    'scheduled_tasks with a zero occurrence' => 'scheduled_tasks:'.str_repeat('a', 64).':0',
]);

test('a mapping outside the scan scope is not evaluated even when malformed', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:App\\Jobs\\*' => ['domain' => 'billing'],
    ]);

    [$result, $diagnostics] = $resolver->apply(['routes' => []], ['routes']);

    expect($diagnostics)->toBe([])
        ->and($result)->toBe(['routes' => []]);
});

test('apply() fills an absent scalar and appends new list values on a matched artifact', function () {
    $resolver = new AnnotationConfigurationResolver([
        'gates:ability:edit-post' => [
            'domain' => 'content',
            'external_services' => ['s3'],
        ],
    ]);

    $artifacts = [
        'gates' => [
            ['id' => 'gates:ability:edit-post', 'ability' => 'edit-post', 'kind' => 'closure', 'parameters' => []],
        ],
    ];

    [$result, $diagnostics] = $resolver->apply($artifacts, ['gates']);

    expect($result['gates'][0]['annotations'])->toBe([
        'domain' => 'content',
        'external_services' => ['s3'],
    ])->and($diagnostics)->toBe([]);
});

test('apply() keeps the existing scalar and warns when the mapping disagrees', function () {
    $resolver = new AnnotationConfigurationResolver([
        'middleware:alias:auth' => ['domain' => 'billing'],
    ]);

    $artifacts = [
        'middleware' => [
            [
                'id' => 'middleware:alias:auth',
                'alias' => 'auth',
                'class' => 'App\\Http\\Middleware\\Authenticate',
                'scope' => 'alias',
                'group' => null,
                'annotations' => ['domain' => 'security'],
            ],
        ],
    ];

    [$result, $diagnostics] = $resolver->apply($artifacts, ['middleware']);

    expect($result['middleware'][0]['annotations'])->toBe(['domain' => 'security'])
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0])->toContain('AN_SOURCE_CONFLICT')
        ->and($diagnostics[0])->toContain('middleware:alias:auth')
        ->and($diagnostics[0])->toContain('domain');
});

test('apply() appends new list values after the existing ones with exact dedupe', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:App\\Jobs\\SendInvoice' => ['external_services' => ['stripe', 'sendgrid']],
    ]);

    $artifacts = [
        'jobs' => [
            [
                'id' => 'jobs:App\\Jobs\\SendInvoice',
                'class' => 'App\\Jobs\\SendInvoice',
                'annotations' => ['external_services' => ['sendgrid', 's3']],
            ],
        ],
    ];

    [$result] = $resolver->apply($artifacts, ['jobs']);

    expect($result['jobs'][0]['annotations']['external_services'])->toBe(['sendgrid', 's3', 'stripe']);
});

test('apply() warns AN_CONFIG_UNMATCHED for an in-scope mapping with no matching artifact', function () {
    $resolver = new AnnotationConfigurationResolver([
        'gates:ability:missing' => ['domain' => 'content'],
    ]);

    [, $diagnostics] = $resolver->apply(['gates' => []], ['gates']);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0])->toContain('AN_CONFIG_UNMATCHED')
        ->and($diagnostics[0])->toContain('gates:ability:missing');
});

test('apply() emits no warning for an unmatched mapping outside the scan scope', function () {
    $resolver = new AnnotationConfigurationResolver([
        'jobs:App\\Jobs\\SendInvoice' => ['domain' => 'billing'],
    ]);

    [$result, $diagnostics] = $resolver->apply(['routes' => []], ['routes']);

    expect($diagnostics)->toBe([])
        ->and($result)->toBe(['routes' => []]);
});
