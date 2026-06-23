<?php

use LaravelNecromancer\Audit\Checks\BroadcastableEventsWithNoChannelCheck;
use LaravelNecromancer\Audit\Checks\ClosureRoutesCheck;
use LaravelNecromancer\Audit\Checks\EmptyCommandDescriptionsCheck;
use LaravelNecromancer\Audit\Checks\EventsWithNoListenersCheck;
use LaravelNecromancer\Audit\Checks\JobsWithNoQueueNameCheck;
use LaravelNecromancer\Audit\Checks\JobsWithNoTimeoutCheck;
use LaravelNecromancer\Audit\Checks\JobsWithNoTriesCheck;
use LaravelNecromancer\Audit\Checks\MissingCastsCheck;
use LaravelNecromancer\Audit\Checks\MissingFillableCheck;
use LaravelNecromancer\Audit\Checks\ModelsWithOpenGuardCheck;
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
