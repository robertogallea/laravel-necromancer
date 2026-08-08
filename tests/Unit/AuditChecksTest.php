<?php

use LaravelNecromancer\Audit\Checks\BroadcastableEventsWithNoChannelCheck;
use LaravelNecromancer\Audit\Checks\ClosureRoutesCheck;
use LaravelNecromancer\Audit\Checks\EmptyCommandDescriptionsCheck;
use LaravelNecromancer\Audit\Checks\EventsWithNoListenersCheck;
use LaravelNecromancer\Audit\Checks\ExternalServiceArtifactsWithoutTestsCheck;
use LaravelNecromancer\Audit\Checks\HighRiskArtifactsWithoutAdrCheck;
use LaravelNecromancer\Audit\Checks\IdentifierStyleCheck;
use LaravelNecromancer\Audit\Checks\InconsistentFlowMetadataCheck;
use LaravelNecromancer\Audit\Checks\JobsWithNoQueueNameCheck;
use LaravelNecromancer\Audit\Checks\JobsWithNoTimeoutCheck;
use LaravelNecromancer\Audit\Checks\JobsWithNoTriesCheck;
use LaravelNecromancer\Audit\Checks\MissingCastsCheck;
use LaravelNecromancer\Audit\Checks\MissingFillableCheck;
use LaravelNecromancer\Audit\Checks\MissingLocalAdrFileCheck;
use LaravelNecromancer\Audit\Checks\ModelsWithOpenGuardCheck;
use LaravelNecromancer\Audit\Checks\NarrativeAnnotationSummaryCheck;
use LaravelNecromancer\Audit\Checks\NonGetRoutesWithoutAuthCheck;
use LaravelNecromancer\Audit\Checks\UnnamedRoutesCheck;

// UnnamedRoutesCheck

test('UnnamedRoutesCheck flags a route with no name', function () {
    $result = (new UnnamedRoutesCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'name' => null, 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('error')
        ->and($result->findings[0]->message)->toContain('GET /orders')
        ->and($result->total)->toBe(1);
});

test('UnnamedRoutesCheck does not flag a named route', function () {
    $result = (new UnnamedRoutesCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index', 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

test('UnnamedRoutesCheck returns zero total when no routes present', function () {
    $result = (new UnnamedRoutesCheck)->run([]);

    expect($result->total)->toBe(0)
        ->and($result->findings)->toBeEmpty();
});

// ClosureRoutesCheck

test('ClosureRoutesCheck flags a route with no controller or action', function () {
    $result = (new ClosureRoutesCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/ping', 'name' => 'ping', 'controller' => null, 'action' => null, 'middleware' => [], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('suggestion')
        ->and($result->findings[0]->message)->toContain('GET /ping');
});

test('ClosureRoutesCheck does not flag a controller-backed route', function () {
    $result = (new ClosureRoutesCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index', 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

// NonGetRoutesWithoutAuthCheck

test('NonGetRoutesWithoutAuthCheck flags a POST route with no auth middleware', function () {
    $result = (new NonGetRoutesWithoutAuthCheck)->run(['routes' => [
        ['method' => 'POST', 'uri' => '/orders', 'name' => 'orders.store', 'controller' => 'OrderController', 'action' => 'store', 'middleware' => [], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('suggestion');
});

test('NonGetRoutesWithoutAuthCheck does not flag a POST route that has auth middleware', function () {
    $result = (new NonGetRoutesWithoutAuthCheck)->run(['routes' => [
        ['method' => 'POST', 'uri' => '/orders', 'name' => 'orders.store', 'controller' => 'OrderController', 'action' => 'store', 'middleware' => ['auth'], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

test('NonGetRoutesWithoutAuthCheck does not flag GET routes regardless of auth', function () {
    $result = (new NonGetRoutesWithoutAuthCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index', 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(0);
});

// MissingFillableCheck

test('MissingFillableCheck flags a model with no fillable', function () {
    $result = (new MissingFillableCheck)->run(['models' => [
        ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => [], 'casts' => ['id' => 'int'], 'relationships' => [], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('warning')
        ->and($result->findings[0]->message)->toContain('Order');
});

test('MissingFillableCheck does not flag a model with fillable defined', function () {
    $result = (new MissingFillableCheck)->run(['models' => [
        ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => ['name'], 'casts' => [], 'relationships' => [], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

test('MissingFillableCheck does not flag a model that uses the guarded strategy', function () {
    $result = (new MissingFillableCheck)->run(['models' => [
        ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => [], 'casts' => [], 'relationships' => [], 'guarded' => ['id'], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

// MissingCastsCheck

test('MissingCastsCheck flags a model with no casts', function () {
    $result = (new MissingCastsCheck)->run(['models' => [
        ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => ['name'], 'casts' => [], 'relationships' => [], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('suggestion');
});

test('MissingCastsCheck does not flag a model with casts defined', function () {
    $result = (new MissingCastsCheck)->run(['models' => [
        ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => ['name'], 'casts' => ['id' => 'int'], 'relationships' => [], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

// ModelsWithOpenGuardCheck

test('ModelsWithOpenGuardCheck flags a model with guarded set to empty array', function () {
    $result = (new ModelsWithOpenGuardCheck)->run(['models' => [
        ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => [], 'casts' => [], 'relationships' => [], 'guarded' => [], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('warning');
});

test('ModelsWithOpenGuardCheck does not flag a model without a guarded key', function () {
    $result = (new ModelsWithOpenGuardCheck)->run(['models' => [
        ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => ['name'], 'casts' => [], 'relationships' => [], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

test('ModelsWithOpenGuardCheck does not flag an open guard constrained by a fillable whitelist', function () {
    $result = (new ModelsWithOpenGuardCheck)->run(['models' => [
        ['class' => 'App\\Models\\CallCandidate', 'table' => 'call_candidates', 'fillable' => ['candidate_id', 'source'], 'casts' => [], 'relationships' => [], 'guarded' => [], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

// EmptyCommandDescriptionsCheck

test('EmptyCommandDescriptionsCheck flags a command with an empty description', function () {
    $result = (new EmptyCommandDescriptionsCheck)->run(['commands' => [
        ['class' => 'App\\Console\\Commands\\PruneOrders', 'signature' => 'orders:prune', 'description' => '', 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('warning')
        ->and($result->findings[0]->message)->toContain('orders:prune');
});

test('EmptyCommandDescriptionsCheck does not flag a command with a description', function () {
    $result = (new EmptyCommandDescriptionsCheck)->run(['commands' => [
        ['class' => 'App\\Console\\Commands\\PruneOrders', 'signature' => 'orders:prune', 'description' => 'Prune old orders', 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

// EventsWithNoListenersCheck

test('EventsWithNoListenersCheck flags an event with no listeners', function () {
    $result = (new EventsWithNoListenersCheck)->run(['events' => [
        ['class' => 'App\\Events\\OrderPlaced', 'listeners' => [], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('warning')
        ->and($result->findings[0]->message)->toContain('OrderPlaced');
});

test('EventsWithNoListenersCheck does not flag an event that has listeners', function () {
    $result = (new EventsWithNoListenersCheck)->run(['events' => [
        ['class' => 'App\\Events\\OrderPlaced', 'listeners' => ['App\\Listeners\\SendConfirmation'], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

// BroadcastableEventsWithNoChannelCheck

test('BroadcastableEventsWithNoChannelCheck flags a broadcastable event with no channels', function () {
    $result = (new BroadcastableEventsWithNoChannelCheck)->run(['events' => [
        ['class' => 'App\\Events\\OrderShipped', 'listeners' => [], 'broadcastable' => true, 'channels' => [], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('warning');
});

test('BroadcastableEventsWithNoChannelCheck does not flag a non-broadcastable event', function () {
    $result = (new BroadcastableEventsWithNoChannelCheck)->run(['events' => [
        ['class' => 'App\\Events\\OrderPlaced', 'listeners' => [], 'broadcastable' => false, 'channels' => [], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(0);
});

// JobsWithNoQueueNameCheck

test('JobsWithNoQueueNameCheck flags a job with no queue name', function () {
    $result = (new JobsWithNoQueueNameCheck)->run(['jobs' => [
        ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => null, 'connection' => 'redis', 'tries' => 3, 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('suggestion')
        ->and($result->findings[0]->message)->toContain('SendInvoiceEmail');
});

test('JobsWithNoQueueNameCheck does not flag a job with a queue name', function () {
    $result = (new JobsWithNoQueueNameCheck)->run(['jobs' => [
        ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => 3, 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

// JobsWithNoTimeoutCheck

test('JobsWithNoTimeoutCheck flags a job with null timeout', function () {
    $result = (new JobsWithNoTimeoutCheck)->run(['jobs' => [
        ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => 3, 'timeout' => null, 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('warning');
});

test('JobsWithNoTimeoutCheck does not flag a job with a timeout set', function () {
    $result = (new JobsWithNoTimeoutCheck)->run(['jobs' => [
        ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => 3, 'timeout' => 60, 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

test('JobsWithNoTimeoutCheck does not count jobs that do not declare a timeout key', function () {
    $result = (new JobsWithNoTimeoutCheck)->run(['jobs' => [
        ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => 3, 'source' => null],
    ]]);

    expect($result->total)->toBe(0)
        ->and($result->findings)->toBeEmpty();
});

// JobsWithNoTriesCheck

test('JobsWithNoTriesCheck flags a job with null tries', function () {
    $result = (new JobsWithNoTriesCheck)->run(['jobs' => [
        ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => null, 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('suggestion');
});

test('JobsWithNoTriesCheck flags a job with tries set to zero', function () {
    $result = (new JobsWithNoTriesCheck)->run(['jobs' => [
        ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => 0, 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1);
});

test('JobsWithNoTriesCheck does not flag a job with tries configured', function () {
    $result = (new JobsWithNoTriesCheck)->run(['jobs' => [
        ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => 3, 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

// HighRiskArtifactsWithoutAdrCheck

test('HighRiskArtifactsWithoutAdrCheck flags a high-risk route with no ADR reference', function () {
    $result = (new HighRiskArtifactsWithoutAdrCheck)->run(['routes' => [
        ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['risk' => 'high'], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('warning')
        ->and($result->findings[0]->message)->toBe('High-risk routes without an ADR reference: POST /billing/cancel')
        ->and($result->total)->toBe(1);
});

test('HighRiskArtifactsWithoutAdrCheck does not flag a high-risk route with an ADR reference', function () {
    $result = (new HighRiskArtifactsWithoutAdrCheck)->run(['routes' => [
        ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['risk' => 'critical', 'adrs' => ['docs/adr/1.md']], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

test('HighRiskArtifactsWithoutAdrCheck ignores artifacts without high/critical risk', function () {
    $result = (new HighRiskArtifactsWithoutAdrCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'annotations' => ['risk' => 'low'], 'source' => null],
        ['method' => 'GET', 'uri' => '/plain', 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(0);
});

test('HighRiskArtifactsWithoutAdrCheck flags a high-risk job with no ADR reference', function () {
    $result = (new HighRiskArtifactsWithoutAdrCheck)->run(['jobs' => [
        ['class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['risk' => 'high'], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->message)->toBe('High-risk jobs without an ADR reference: App\\Jobs\\SendInvoice');
});

// ExternalServiceArtifactsWithoutTestsCheck

test('ExternalServiceArtifactsWithoutTestsCheck flags an external-service route with no matching test', function () {
    $result = (new ExternalServiceArtifactsWithoutTestsCheck)->run([
        'routes' => [
            ['method' => 'POST', 'uri' => '/stripe/webhook', 'controller' => 'App\\Http\\Controllers\\StripeController', 'annotations' => ['external_services' => ['stripe']], 'source' => null],
        ],
        'tests' => [],
    ]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('warning');
});

test('ExternalServiceArtifactsWithoutTestsCheck does not flag a route with a matching test subject', function () {
    $result = (new ExternalServiceArtifactsWithoutTestsCheck)->run([
        'routes' => [
            ['method' => 'POST', 'uri' => '/stripe/webhook', 'controller' => 'App\\Http\\Controllers\\StripeController', 'annotations' => ['external_services' => ['stripe']], 'source' => null],
        ],
        'tests' => [
            ['subject' => 'App\\Http\\Controllers\\StripeController'],
        ],
    ]);

    expect($result->findings)->toBeEmpty();
});

test('ExternalServiceArtifactsWithoutTestsCheck ignores artifacts without external_services', function () {
    $result = (new ExternalServiceArtifactsWithoutTestsCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(0);
});

test('ExternalServiceArtifactsWithoutTestsCheck flags a gate declaring external services, since it has no matchable subject', function () {
    $result = (new ExternalServiceArtifactsWithoutTestsCheck)->run([
        'gates' => [
            ['ability' => 'sync-external-billing', 'kind' => 'closure', 'annotations' => ['external_services' => ['stripe']], 'source' => null],
        ],
        'tests' => [
            ['subject' => 'App\\Http\\Controllers\\StripeController'],
        ],
    ]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->message)->toContain('gates');
});

// NarrativeAnnotationSummaryCheck

test('NarrativeAnnotationSummaryCheck flags a summary over 200 characters', function () {
    $result = (new NarrativeAnnotationSummaryCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'annotations' => ['summary' => str_repeat('a', 201)], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('suggestion');
});

test('NarrativeAnnotationSummaryCheck does not flag a compact summary', function () {
    $result = (new NarrativeAnnotationSummaryCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'annotations' => ['summary' => 'Cancels an active subscription.'], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

test('NarrativeAnnotationSummaryCheck ignores artifacts without a summary', function () {
    $result = (new NarrativeAnnotationSummaryCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(0);
});

test('NarrativeAnnotationSummaryCheck flags an overlong summary on a model', function () {
    $result = (new NarrativeAnnotationSummaryCheck)->run(['models' => [
        ['class' => 'App\\Models\\Order', 'annotations' => ['summary' => str_repeat('a', 201)], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1);
});

// InconsistentFlowMetadataCheck

test('InconsistentFlowMetadataCheck flags routes in the same flow with different risk levels', function () {
    $result = (new InconsistentFlowMetadataCheck)->run(['routes' => [
        ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['flow' => 'subscription-cancellation', 'risk' => 'high'], 'source' => null],
        ['method' => 'POST', 'uri' => '/billing/refund', 'annotations' => ['flow' => 'subscription-cancellation', 'risk' => 'low'], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(2)
        ->and($result->findings[0]->severity)->toBe('warning')
        ->and($result->findings[0]->message)->toContain('risk')
        ->and($result->total)->toBe(2);
});

test('InconsistentFlowMetadataCheck flags routes in the same flow with different domains', function () {
    $result = (new InconsistentFlowMetadataCheck)->run(['routes' => [
        ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['flow' => 'subscription-cancellation', 'domain' => 'billing'], 'source' => null],
        ['method' => 'POST', 'uri' => '/billing/refund', 'annotations' => ['flow' => 'subscription-cancellation', 'domain' => 'payments'], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(2)
        ->and($result->findings[0]->message)->toContain('domain');
});

test('InconsistentFlowMetadataCheck does not flag a flow where domain and risk agree', function () {
    $result = (new InconsistentFlowMetadataCheck)->run(['routes' => [
        ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['flow' => 'subscription-cancellation', 'domain' => 'billing', 'risk' => 'high'], 'source' => null],
        ['method' => 'POST', 'uri' => '/billing/refund', 'annotations' => ['flow' => 'subscription-cancellation', 'domain' => 'billing', 'risk' => 'high'], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(2);
});

test('InconsistentFlowMetadataCheck ignores a route whose flow has no siblings', function () {
    $result = (new InconsistentFlowMetadataCheck)->run(['routes' => [
        ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['flow' => 'subscription-cancellation', 'risk' => 'high'], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(0);
});

test('InconsistentFlowMetadataCheck ignores routes without a flow', function () {
    $result = (new InconsistentFlowMetadataCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(0);
});

test('InconsistentFlowMetadataCheck emits one finding per artifact even when it conflicts on multiple fields', function () {
    $result = (new InconsistentFlowMetadataCheck)->run(['routes' => [
        ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['flow' => 'subscription-cancellation', 'domain' => 'billing', 'risk' => 'high'], 'source' => null],
        ['method' => 'POST', 'uri' => '/billing/refund', 'annotations' => ['flow' => 'subscription-cancellation', 'domain' => 'payments', 'risk' => 'low'], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(2)
        ->and($result->total)->toBe(2)
        ->and($result->findings[0]->message)->toContain('domain')->toContain('risk');
});

test('InconsistentFlowMetadataCheck flags a flow shared between a route and a job', function () {
    $result = (new InconsistentFlowMetadataCheck)->run([
        'routes' => [
            ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['flow' => 'subscription-cancellation', 'domain' => 'billing'], 'source' => null],
        ],
        'jobs' => [
            ['class' => 'App\\Jobs\\RevokeAccess', 'annotations' => ['flow' => 'subscription-cancellation', 'domain' => 'access'], 'source' => null],
        ],
    ]);

    expect($result->findings)->toHaveCount(2)
        ->and($result->total)->toBe(2);
});

// IdentifierStyleCheck

test('IdentifierStyleCheck flags a non-canonical domain', function () {
    $result = (new IdentifierStyleCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'annotations' => ['domain' => 'Billing'], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('suggestion')
        ->and($result->total)->toBe(1);
});

test('IdentifierStyleCheck does not flag a canonical lowercase kebab-case domain', function () {
    $result = (new IdentifierStyleCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'annotations' => ['domain' => 'billing'], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

test('IdentifierStyleCheck flags a non-canonical dotted capability', function () {
    $result = (new IdentifierStyleCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'annotations' => ['capability' => 'Subscription.Cancel'], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1);
});

test('IdentifierStyleCheck does not flag a canonical lowercase dotted capability', function () {
    $result = (new IdentifierStyleCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'annotations' => ['capability' => 'subscription.cancel'], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

test('IdentifierStyleCheck flags case-insensitive near-duplicate domains as separate from noncanonical ones', function () {
    $result = (new IdentifierStyleCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/a', 'annotations' => ['domain' => 'sign-up'], 'source' => null],
        ['method' => 'GET', 'uri' => '/b', 'annotations' => ['domain' => 'signup'], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(2)
        ->and($result->findings[0]->message)->toContain('near-duplicate')
        ->and($result->total)->toBe(2);
});

test('IdentifierStyleCheck does not flag two artifacts declaring the same canonical domain', function () {
    $result = (new IdentifierStyleCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/a', 'annotations' => ['domain' => 'billing'], 'source' => null],
        ['method' => 'GET', 'uri' => '/b', 'annotations' => ['domain' => 'billing'], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty();
});

test('IdentifierStyleCheck ignores artifacts without any identifier fields', function () {
    $result = (new IdentifierStyleCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'annotations' => ['summary' => 'Lists orders.'], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(0);
});

test('IdentifierStyleCheck flags both sides of a case-differing near-duplicate pair', function () {
    $result = (new IdentifierStyleCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/a', 'annotations' => ['domain' => 'billing'], 'source' => null],
        ['method' => 'GET', 'uri' => '/b', 'annotations' => ['domain' => 'Billing'], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(2)
        ->and($result->total)->toBe(2);

    $messages = implode(' | ', array_map(fn ($f) => $f->message, $result->findings));
    expect($messages)->toContain('Non-canonical')
        ->toContain('near-duplicate');
});

test('IdentifierStyleCheck checks each external service item individually', function () {
    $result = (new IdentifierStyleCheck)->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'annotations' => ['external_services' => ['Stripe', 'slack']], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->total)->toBe(2);
});

// MissingLocalAdrFileCheck

test('MissingLocalAdrFileCheck flags a local ADR path that does not exist on disk', function () {
    $basePath = sys_get_temp_dir().'/necromancer-adr-test-'.uniqid();
    mkdir($basePath, recursive: true);

    $result = (new MissingLocalAdrFileCheck($basePath))->run(['routes' => [
        ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['adrs' => ['docs/adr/001-missing.md']], 'source' => null],
    ]]);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe('warning')
        ->and($result->total)->toBe(1);

    rmdir($basePath);
});

test('MissingLocalAdrFileCheck does not flag a local ADR path that exists on disk', function () {
    $basePath = sys_get_temp_dir().'/necromancer-adr-test-'.uniqid();
    mkdir($basePath.'/docs/adr', recursive: true);
    file_put_contents($basePath.'/docs/adr/001-present.md', '# ADR');

    $result = (new MissingLocalAdrFileCheck($basePath))->run(['routes' => [
        ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['adrs' => ['docs/adr/001-present.md']], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(1);

    unlink($basePath.'/docs/adr/001-present.md');
    rmdir($basePath.'/docs/adr');
    rmdir($basePath.'/docs');
    rmdir($basePath);
});

test('MissingLocalAdrFileCheck does not check absolute URIs for reachability', function () {
    $basePath = sys_get_temp_dir().'/necromancer-adr-test-'.uniqid();
    mkdir($basePath, recursive: true);

    $result = (new MissingLocalAdrFileCheck($basePath))->run(['routes' => [
        ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['adrs' => ['https://example.com/adr/001.md']], 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(0);

    rmdir($basePath);
});

test('MissingLocalAdrFileCheck ignores artifacts without adrs', function () {
    $basePath = sys_get_temp_dir().'/necromancer-adr-test-'.uniqid();
    mkdir($basePath, recursive: true);

    $result = (new MissingLocalAdrFileCheck($basePath))->run(['routes' => [
        ['method' => 'GET', 'uri' => '/orders', 'source' => null],
    ]]);

    expect($result->findings)->toBeEmpty()
        ->and($result->total)->toBe(0);

    rmdir($basePath);
});
