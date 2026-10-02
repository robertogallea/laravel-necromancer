<?php

use LaravelNecromancer\Okf\ArtifactConcept;
use LaravelNecromancer\Okf\ArtifactConceptBuilder;
use LaravelNecromancer\Okf\ConceptEnrichment;
use LaravelNecromancer\Okf\ConceptLink;
use LaravelNecromancer\Relationships\Provenance;
use LaravelNecromancer\Relationships\Relationship;
use LaravelNecromancer\Relationships\RelationshipEvidence;
use LaravelNecromancer\Relationships\RelationshipResolver;
use LaravelNecromancer\Relationships\RelationshipType;

/**
 * Builds a concept for one artifact with the Relationships resolved from a
 * manifest containing only that artifact.
 *
 * @param  array<string, mixed>  $artifact
 * @param  array<string, ConceptLink>  $classIndex
 */
function buildConceptWithRelationships(string $type, array $artifact, array $classIndex = []): ArtifactConcept
{
    /** @var list<Relationship> $relationships */
    $relationships = (new RelationshipResolver)->resolve(['artifacts' => [$type => [$artifact]]]);

    return (new ArtifactConceptBuilder)->build($type, $artifact, '2026-08-07T12:00:00+02:00', $classIndex, relationships: $relationships);
}

function sampleEnrichment(array $overrides = []): ConceptEnrichment
{
    return new ConceptEnrichment(
        description: $overrides['description'] ?? 'Delivers invoice emails asynchronously.',
        narrative: $overrides['narrative'] ?? 'This job decouples email delivery from the request cycle.',
        provider: $overrides['provider'] ?? 'anthropic',
        model: $overrides['model'] ?? 'claude-sonnet-4-6',
        promptVersion: $overrides['promptVersion'] ?? '1',
        privacyPolicy: $overrides['privacyPolicy'] ?? 'excludes-source-framework-config-adr-bodies',
        cacheKey: $overrides['cacheKey'] ?? 'sha256:abc123',
        cached: $overrides['cached'] ?? false,
    );
}

function buildJobConcept(array $overrides = []): ArtifactConcept
{
    $artifact = array_merge([
        'id' => 'jobs:App\\Jobs\\SendInvoice',
        'class' => 'App\\Jobs\\SendInvoice',
        'queue' => 'emails',
        'tries' => 3,
        'source' => ['file' => 'app/Jobs/SendInvoice.php', 'line' => 12],
    ], $overrides);

    return (new ArtifactConceptBuilder)->build('jobs', $artifact, '2026-08-07T12:00:00+02:00');
}

test('build() derives the filename from a slugified title and an 8-character id hash', function () {
    $concept = buildJobConcept();

    $expectedHash = substr(hash('sha256', 'jobs:App\\Jobs\\SendInvoice'), 0, 8);
    expect($concept->filename)->toBe("app-jobs-sendinvoice-{$expectedHash}.md");
});

test('build() sets the front matter title, type, and kind', function () {
    $concept = buildJobConcept();

    expect($concept->content)->toContain('title: "App\\\\Jobs\\\\SendInvoice"')
        ->and($concept->content)->toContain('type: "artifact"')
        ->and($concept->content)->toContain('kind: "jobs"');
});

test('build() records the canonical id, schema version, and manifest generated_at under the necromancer namespace', function () {
    $concept = buildJobConcept();

    expect($concept->content)->toContain('id: "jobs:App\\\\Jobs\\\\SendInvoice"')
        ->and($concept->content)->toContain('schema_version: 1')
        ->and($concept->content)->toContain('generated_at: "2026-08-07T12:00:00+02:00"');
});

test('build() excludes id, annotations, source, and route_metadata from Discovered Facts', function () {
    $concept = buildJobConcept([
        'annotations' => ['domain' => 'billing'],
    ]);

    expect($concept->content)->toContain('queue: "emails"')
        ->and($concept->content)->toContain('tries: 3')
        ->and($concept->content)->not->toContain('facts:
    id:')
        ->and($concept->content)->not->toContain('facts:
    source:');
});

test('build() renders an Architectural Context section only when annotations are declared', function () {
    $withAnnotations = buildJobConcept(['annotations' => ['domain' => 'billing', 'risk' => 'high']]);
    $withoutAnnotations = buildJobConcept();

    expect($withAnnotations->content)->toContain('## Architectural Context')
        ->and($withAnnotations->content)->toContain('domain: billing · risk: high')
        ->and($withoutAnnotations->content)->not->toContain('## Architectural Context');
});

test('build() puts route_metadata under framework_metadata, never merged into facts', function () {
    $artifact = [
        'id' => 'routes:POST:billing/cancel',
        'method' => 'POST',
        'uri' => 'billing/cancel',
        'controller' => 'App\\Http\\Controllers\\BillingController',
        'route_metadata' => ['raw' => ['head' => ['title' => 'x']]],
        'source' => null,
    ];

    $concept = (new ArtifactConceptBuilder)->build('routes', $artifact, '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('framework_metadata:')
        ->and($concept->content)->not->toContain('facts:
    route_metadata:');
});

test('build() titles a route as method and uri', function () {
    $artifact = ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'source' => null];
    $concept = (new ArtifactConceptBuilder)->build('routes', $artifact, '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('title: "GET orders"');
});

test('build() titles a gate by its ability, with no class field', function () {
    $artifact = ['id' => 'gates:ability:edit-post', 'ability' => 'edit-post', 'kind' => 'closure', 'parameters' => [], 'source' => null];
    $concept = (new ArtifactConceptBuilder)->build('gates', $artifact, '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('title: "edit-post"');
});

test('build() titles a scheduled task by its command', function () {
    $artifact = ['id' => 'scheduled_tasks:abc123:1', 'command' => 'orders:prune', 'expression' => '0 0 * * *', 'source' => null];
    $concept = (new ArtifactConceptBuilder)->build('scheduled_tasks', $artifact, '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('title: "orders:prune"');
});

test('build() is deterministic: identical input produces byte-identical output', function () {
    $concept1 = buildJobConcept(['annotations' => ['domain' => 'billing', 'external_services' => ['stripe']]]);
    $concept2 = buildJobConcept(['annotations' => ['domain' => 'billing', 'external_services' => ['stripe']]]);

    expect($concept1->content)->toBe($concept2->content)
        ->and($concept1->filename)->toBe($concept2->filename);
});

test('build() exposes the artifact id on the concept value object', function () {
    $concept = buildJobConcept();

    expect($concept->id)->toBe('jobs:App\\Jobs\\SendInvoice');
});

test('identify() returns the same id, title, and filename that build() would produce', function () {
    $artifact = ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null];
    $builder = new ArtifactConceptBuilder;

    $identity = $builder->identify('jobs', $artifact);
    $concept = $builder->build('jobs', $artifact, '2026-08-07T12:00:00+02:00');

    expect($identity['id'])->toBe($concept->id)
        ->and($identity['filename'])->toBe($concept->filename)
        ->and($identity['title'])->toBe('App\\Jobs\\SendInvoice');
});

test('build() omits the Relationships section for a type with no relationship fields', function () {
    $concept = buildJobConcept();

    expect($concept->content)->not->toContain('## Relationships');
});

test('build() links a route controller present in the class index', function () {
    $artifact = ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'source' => null];
    $classIndex = ['App\\Http\\Controllers\\OrderController' => new ConceptLink('OrderController', '/artifacts/order-controller-abcd1234.md')];

    $concept = buildConceptWithRelationships('routes', $artifact, $classIndex);

    expect($concept->content)->toContain('## Relationships')
        ->and($concept->content)->toContain('- **controller**: [App\\Http\\Controllers\\OrderController](/artifacts/order-controller-abcd1234.md)');
});

test('build() renders an unresolved route controller as plain text', function () {
    $artifact = ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'source' => null];

    $concept = buildConceptWithRelationships('routes', $artifact);

    expect($concept->content)->toContain('- **controller**: App\\Http\\Controllers\\OrderController')
        ->and($concept->content)->not->toContain('[App\\Http\\Controllers\\OrderController](');
});

test('build() links model relationships, policy, and observers when resolvable', function () {
    $classIndex = [
        'App\\Models\\Customer' => new ConceptLink('Customer', '/artifacts/customer.md'),
        'App\\Policies\\OrderPolicy' => new ConceptLink('OrderPolicy', '/artifacts/order-policy.md'),
        'App\\Observers\\OrderObserver' => new ConceptLink('OrderObserver', '/artifacts/order-observer.md'),
    ];

    $artifact = [
        'id' => 'models:App\\Models\\Order',
        'class' => 'App\\Models\\Order',
        'relationships' => [
            ['type' => 'belongsTo', 'related' => 'App\\Models\\Customer', 'method' => 'customer'],
            ['type' => 'belongsTo', 'related' => 'App\\Models\\Unknown', 'method' => 'unknown'],
        ],
        'policy' => 'App\\Policies\\OrderPolicy',
        'observers' => ['App\\Observers\\OrderObserver'],
        'source' => null,
    ];

    $concept = buildConceptWithRelationships('models', $artifact, $classIndex);

    expect($concept->content)->toContain('- **customer**: belongsTo → [App\\Models\\Customer](/artifacts/customer.md)')
        ->and($concept->content)->toContain('- **unknown**: belongsTo → App\\Models\\Unknown')
        ->and($concept->content)->toContain('- **policy**: [App\\Policies\\OrderPolicy](/artifacts/order-policy.md)')
        ->and($concept->content)->toContain('- **observers**: [App\\Observers\\OrderObserver](/artifacts/order-observer.md)');
});

test('build() links event listeners and listener handled events', function () {
    $classIndex = ['App\\Listeners\\SendOrderConfirmation' => new ConceptLink('SendOrderConfirmation', '/artifacts/listener.md')];

    $event = ['id' => 'events:App\\Events\\OrderPlaced', 'class' => 'App\\Events\\OrderPlaced', 'listeners' => ['App\\Listeners\\SendOrderConfirmation'], 'source' => null];
    $concept = buildConceptWithRelationships('events', $event, $classIndex);

    expect($concept->content)->toContain('- **listeners**: [App\\Listeners\\SendOrderConfirmation](/artifacts/listener.md)');

    $classIndex2 = ['App\\Events\\OrderPlaced' => new ConceptLink('OrderPlaced', '/artifacts/event.md')];
    $listener = ['id' => 'listeners:App\\Listeners\\SendOrderConfirmation', 'class' => 'App\\Listeners\\SendOrderConfirmation', 'handles' => ['App\\Events\\OrderPlaced'], 'source' => null];
    $listenerConcept = buildConceptWithRelationships('listeners', $listener, $classIndex2);

    expect($listenerConcept->content)->toContain('- **handles**: [App\\Events\\OrderPlaced](/artifacts/event.md)');
});

test('build() links a policy or observer model when resolvable', function () {
    $classIndex = ['App\\Models\\Order' => new ConceptLink('Order', '/artifacts/order.md')];

    $policy = ['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order', 'source' => null];
    $policyConcept = buildConceptWithRelationships('policies', $policy, $classIndex);

    expect($policyConcept->content)->toContain('- **model**: [App\\Models\\Order](/artifacts/order.md)');

    $observer = ['id' => 'observers:App\\Observers\\OrderObserver', 'class' => 'App\\Observers\\OrderObserver', 'model' => 'App\\Models\\Order', 'source' => null];
    $observerConcept = buildConceptWithRelationships('observers', $observer, $classIndex);

    expect($observerConcept->content)->toContain('- **model**: [App\\Models\\Order](/artifacts/order.md)');
});

test('build() links a declared domain/flow value back to its synthesized group concept when resolvable', function () {
    $groupIndex = [
        'domain:billing' => new ConceptLink('billing', '/artifacts/domain-billing.md'),
        'flow:invoicing' => new ConceptLink('invoicing', '/artifacts/flow-invoicing.md'),
    ];

    $concept = (new ArtifactConceptBuilder)->build('jobs', [
        'id' => 'jobs:App\\Jobs\\SendInvoice',
        'class' => 'App\\Jobs\\SendInvoice',
        'annotations' => ['domain' => 'billing', 'flow' => 'invoicing'],
        'source' => null,
    ], '2026-08-07T12:00:00+02:00', [], [], $groupIndex);

    expect($concept->content)->toContain('domain: [billing](/artifacts/domain-billing.md)')
        ->and($concept->content)->toContain('flow: [invoicing](/artifacts/flow-invoicing.md)');
});

test('build() renders an unresolved domain/flow value as plain text', function () {
    $concept = buildJobConcept(['annotations' => ['domain' => 'billing', 'flow' => 'invoicing']]);

    expect($concept->content)->toContain('domain: billing')
        ->and($concept->content)->toContain('flow: invoicing')
        ->and($concept->content)->not->toContain('domain: [billing]')
        ->and($concept->content)->not->toContain('flow: [invoicing]');
});

test('build() omits the enrichment front matter and body section when no enrichment is passed', function () {
    $concept = buildJobConcept();

    expect($concept->content)->not->toContain('enrichment:')
        ->and($concept->content)->not->toContain('## AI-Enriched Summary');
});

test('build() records enrichment provenance under necromancer.enrichment without touching facts or annotations', function () {
    $concept = (new ArtifactConceptBuilder)->build(
        'jobs',
        ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'queue' => 'emails', 'annotations' => ['domain' => 'billing'], 'source' => null],
        '2026-08-07T12:00:00+02:00',
        enrichment: sampleEnrichment(),
    );

    expect($concept->content)->toContain('enrichment:')
        ->and($concept->content)->toContain('provider: "anthropic"')
        ->and($concept->content)->toContain('model: "claude-sonnet-4-6"')
        ->and($concept->content)->toContain('prompt_version: "1"')
        ->and($concept->content)->toContain('privacy_policy: "excludes-source-framework-config-adr-bodies"')
        ->and($concept->content)->toContain('cache_key: "sha256:abc123"')
        ->and($concept->content)->toContain('cached: false')
        // facts/annotations must be byte-identical to the non-enriched build
        ->and($concept->content)->toContain('queue: "emails"')
        ->and($concept->content)->toContain('domain: billing');
});

test('build() adds description to front matter and a narrative body section when enriched', function () {
    $concept = (new ArtifactConceptBuilder)->build(
        'jobs',
        ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        '2026-08-07T12:00:00+02:00',
        enrichment: sampleEnrichment(),
    );

    expect($concept->content)->toContain('description: "Delivers invoice emails asynchronously."')
        ->and($concept->content)->toContain('## AI-Enriched Summary')
        ->and($concept->content)->toContain('This job decouples email delivery from the request cycle.');
});

test('build() keeps the same id and filename whether or not it is enriched', function () {
    $artifact = ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null];
    $builder = new ArtifactConceptBuilder;

    $plain = $builder->build('jobs', $artifact, '2026-08-07T12:00:00+02:00');
    $enriched = $builder->build('jobs', $artifact, '2026-08-07T12:00:00+02:00', enrichment: sampleEnrichment());

    expect($enriched->id)->toBe($plain->id)
        ->and($enriched->filename)->toBe($plain->filename);
});

test('build() links a locally resolvable declared ADR and renders external ADR URIs as links too', function () {
    $adrIndex = ['docs/adr/0004-x.md' => new ConceptLink('0004-x', '/artifacts/adr-0004-x.md')];

    $concept = (new ArtifactConceptBuilder)->build('jobs', [
        'id' => 'jobs:App\\Jobs\\SendInvoice',
        'class' => 'App\\Jobs\\SendInvoice',
        'annotations' => ['adrs' => ['docs/adr/0004-x.md', 'https://example.com/adr/0005']],
        'source' => null,
    ], '2026-08-07T12:00:00+02:00', [], $adrIndex);

    expect($concept->content)->toContain('adrs: [docs/adr/0004-x.md](/artifacts/adr-0004-x.md), [https://example.com/adr/0005](https://example.com/adr/0005)');
});

test('build() lists a listener\'s handled events in its own declared order, even when the events were resolved first', function () {
    $listener = ['id' => 'listeners:App\\Listeners\\Audit', 'class' => 'App\\Listeners\\Audit', 'handles' => ['App\\Events\\OrderShipped', 'App\\Events\\OrderPlaced'], 'source' => null];
    $manifest = ['artifacts' => [
        'events' => [
            ['id' => 'events:App\\Events\\OrderPlaced', 'class' => 'App\\Events\\OrderPlaced', 'listeners' => ['App\\Listeners\\Audit']],
            ['id' => 'events:App\\Events\\OrderShipped', 'class' => 'App\\Events\\OrderShipped', 'listeners' => ['App\\Listeners\\Audit']],
        ],
        'listeners' => [$listener],
    ]];

    $concept = (new ArtifactConceptBuilder)->build('listeners', $listener, '2026-08-07T12:00:00+02:00', relationships: (new RelationshipResolver)->resolve($manifest));

    expect($concept->content)->toContain('- **handles**: App\\Events\\OrderShipped, App\\Events\\OrderPlaced');
});

test('build() keeps a value repeated in a relationship fact, as the fact declares it', function () {
    $event = ['id' => 'events:App\\Events\\OrderPlaced', 'class' => 'App\\Events\\OrderPlaced', 'listeners' => ['App\\Listeners\\Audit', 'App\\Listeners\\Audit'], 'source' => null];

    $concept = buildConceptWithRelationships('events', $event);

    expect($concept->content)->toContain('- **listeners**: App\\Listeners\\Audit, App\\Listeners\\Audit');
});

/**
 * Builds a concept from hand-built Relationships and an Artifact-ID index.
 *
 * @param  array<string, mixed>  $artifact
 * @param  list<Relationship>  $relationships
 * @param  array<string, ConceptLink>  $idIndex
 */
function buildConceptFromRelationships(string $type, array $artifact, array $relationships, array $idIndex = []): ArtifactConcept
{
    return (new ArtifactConceptBuilder)->build($type, $artifact, '2026-08-07T12:00:00+02:00', relationships: $relationships, idIndex: $idIndex);
}

test('build() renders a route\'s middleware on one uses_middleware line, linked by Artifact ID and qualified by group', function () {
    $route = ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'source' => null];
    $relationships = [
        new Relationship('routes:GET:orders', RelationshipType::UsesMiddleware, 'middleware:group:web:App\\Http\\Middleware\\EncryptCookies', [Provenance::Runtime], true, ['groups' => ['web'], 'direct' => false]),
        new Relationship('routes:GET:orders', RelationshipType::UsesMiddleware, 'middleware:alias:auth:App\\Http\\Middleware\\Authenticate', [Provenance::Runtime], true, ['groups' => [], 'direct' => true]),
        new Relationship('routes:GET:orders', RelationshipType::UsesMiddleware, 'throttle', [Provenance::Runtime], false, ['groups' => ['api', 'web'], 'direct' => false]),
    ];
    $idIndex = [
        'middleware:group:web:App\\Http\\Middleware\\EncryptCookies' => new ConceptLink('App\\Http\\Middleware\\EncryptCookies (group)', '/artifacts/encrypt.md'),
        'middleware:alias:auth:App\\Http\\Middleware\\Authenticate' => new ConceptLink('App\\Http\\Middleware\\Authenticate (alias)', '/artifacts/auth.md'),
    ];

    $concept = buildConceptFromRelationships('routes', $route, $relationships, $idIndex);

    expect($concept->content)->toContain("## Relationships\n\n- **uses_middleware**: [App\\Http\\Middleware\\EncryptCookies (group)](/artifacts/encrypt.md) (via web), [App\\Http\\Middleware\\Authenticate (alias)](/artifacts/auth.md), throttle (via api, web)\n");
});

test('build() renders a route\'s authorized_by targets qualified by ability, raw ability when unresolved', function () {
    $route = ['id' => 'routes:PUT:orders/{order}', 'method' => 'PUT', 'uri' => 'orders/{order}', 'source' => null];
    $relationships = [
        new Relationship('routes:PUT:orders/{order}', RelationshipType::AuthorizedBy, 'policies:App\\Policies\\OrderPolicy', [Provenance::Reflection], true, ['ability' => 'update', 'models' => ['App\\Models\\Order']]),
        new Relationship('routes:PUT:orders/{order}', RelationshipType::AuthorizedBy, 'gates:ability:edit-orders', [Provenance::Reflection], true, ['ability' => 'edit-orders', 'models' => []]),
        new Relationship('routes:PUT:orders/{order}', RelationshipType::AuthorizedBy, 'publish', [Provenance::Reflection], false, ['ability' => 'publish', 'models' => []]),
    ];
    $idIndex = [
        'policies:App\\Policies\\OrderPolicy' => new ConceptLink('App\\Policies\\OrderPolicy', '/artifacts/order-policy.md'),
        'gates:ability:edit-orders' => new ConceptLink('edit-orders', '/artifacts/edit-orders.md'),
    ];

    $concept = buildConceptFromRelationships('routes', $route, $relationships, $idIndex);

    expect($concept->content)->toContain('- **authorized_by**: [App\\Policies\\OrderPolicy](/artifacts/order-policy.md) (update), [edit-orders](/artifacts/edit-orders.md) (edit-orders), publish (publish)'."\n");
});

test('build() keeps a model\'s policy as its legacy policy line, never an authorized_by line', function () {
    $model = ['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'policy' => 'App\\Policies\\OrderPolicy', 'source' => null];
    $relationships = (new RelationshipResolver)->resolve(['artifacts' => [
        'models' => [$model],
        'policies' => [['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'source' => null]],
    ]]);
    $idIndex = ['policies:App\\Policies\\OrderPolicy' => new ConceptLink('App\\Policies\\OrderPolicy', '/artifacts/order-policy.md')];

    $concept = buildConceptFromRelationships('models', $model, $relationships, $idIndex);

    expect($concept->content)->toContain('- **policy**: App\\Policies\\OrderPolicy')
        ->and($concept->content)->not->toContain('authorized_by');
});

test('build() renders dispatched targets on one dispatches line, qualified by their modes', function () {
    $action = ['id' => 'actions:App\\Actions\\PlaceOrder', 'class' => 'App\\Actions\\PlaceOrder', 'source' => null];
    $relationships = [
        new Relationship('actions:App\\Actions\\PlaceOrder', RelationshipType::Dispatches, 'events:App\\Events\\OrderPlaced', [Provenance::Source], true, ['methods' => ['handle'], 'modes' => []]),
        new Relationship('actions:App\\Actions\\PlaceOrder', RelationshipType::Dispatches, 'jobs:App\\Jobs\\SendInvoice', [Provenance::Source], true, ['methods' => ['handle'], 'modes' => ['queued', 'sync']]),
        new Relationship('actions:App\\Actions\\PlaceOrder', RelationshipType::Dispatches, 'App\\Jobs\\Unknown', [Provenance::Source], false, ['methods' => ['handle'], 'modes' => ['queued']]),
    ];
    $idIndex = [
        'events:App\\Events\\OrderPlaced' => new ConceptLink('App\\Events\\OrderPlaced', '/artifacts/order-placed.md'),
        'jobs:App\\Jobs\\SendInvoice' => new ConceptLink('App\\Jobs\\SendInvoice', '/artifacts/send-invoice.md'),
    ];

    $concept = buildConceptFromRelationships('actions', $action, $relationships, $idIndex);

    expect($concept->content)->toContain('- **dispatches**: [App\\Events\\OrderPlaced](/artifacts/order-placed.md), [App\\Jobs\\SendInvoice](/artifacts/send-invoice.md) (queued, sync), App\\Jobs\\Unknown (queued)'."\n");
});

test('build() renders tests on one tested_by line, marking namespace matches', function () {
    $model = ['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'source' => null];
    $relationships = [
        new Relationship('models:App\\Models\\Order', RelationshipType::TestedBy, 'tests:tests/Unit/Models/OrderTest.php', [Provenance::Source], true, ['match' => 'exact']),
        new Relationship('models:App\\Models\\Order', RelationshipType::TestedBy, 'tests:tests/Feature/ModelsTest.php', [Provenance::Source], true, ['match' => 'namespace']),
    ];
    $idIndex = [
        'tests:tests/Unit/Models/OrderTest.php' => new ConceptLink('tests/Unit/Models/OrderTest.php', '/artifacts/order-test.md'),
        'tests:tests/Feature/ModelsTest.php' => new ConceptLink('tests/Feature/ModelsTest.php', '/artifacts/models-test.md'),
    ];

    $concept = buildConceptFromRelationships('models', $model, $relationships, $idIndex);

    expect($concept->content)->toContain('- **tested_by**: [tests/Unit/Models/OrderTest.php](/artifacts/order-test.md), [tests/Feature/ModelsTest.php](/artifacts/models-test.md) (namespace match)'."\n");
});

test('build() marks tests linked by a source reference', function () {
    $model = ['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'source' => null];
    $relationships = [
        new Relationship('models:App\\Models\\Order', RelationshipType::TestedBy, 'tests:tests/Feature/CheckoutTest.php', [Provenance::Source], true, ['match' => 'reference']),
    ];
    $idIndex = ['tests:tests/Feature/CheckoutTest.php' => new ConceptLink('tests/Feature/CheckoutTest.php', '/artifacts/checkout-test.md')];

    $concept = buildConceptFromRelationships('models', $model, $relationships, $idIndex);

    expect($concept->content)->toContain('- **tested_by**: [tests/Feature/CheckoutTest.php](/artifacts/checkout-test.md) (reference)'."\n");
});

test('build() renders source-side lines after every legacy line, in uses_middleware, validates_with, authorized_by, dispatches, tested_by order', function () {
    $route = ['id' => 'routes:POST:orders', 'method' => 'POST', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'source' => null];
    $relationships = [
        new Relationship('routes:POST:orders', RelationshipType::TestedBy, 'tests:tests/Feature/OrderTest.php', [Provenance::Source], true, ['match' => 'exact']),
        new Relationship('routes:POST:orders', RelationshipType::AuthorizedBy, 'create', [Provenance::Reflection], false, ['ability' => 'create', 'models' => []]),
        new Relationship('routes:POST:orders', RelationshipType::ValidatesWith, 'form_requests:App\\Http\\Requests\\StoreOrderRequest', [Provenance::Reflection], true),
        new Relationship('routes:POST:orders', RelationshipType::UsesMiddleware, 'auth', [Provenance::Runtime], false, ['groups' => [], 'direct' => true]),
        new Relationship('routes:POST:orders', RelationshipType::HandledBy, 'App\\Http\\Controllers\\OrderController', [Provenance::Runtime], false, ['action' => 'store'], [
            new RelationshipEvidence('routes:POST:orders', 'controller', 'App\\Http\\Controllers\\OrderController'),
        ]),
    ];
    $idIndex = ['form_requests:App\\Http\\Requests\\StoreOrderRequest' => new ConceptLink('App\\Http\\Requests\\StoreOrderRequest', '/artifacts/store-order-request.md')];

    $concept = buildConceptFromRelationships('routes', $route, $relationships, $idIndex);

    expect($concept->content)->toContain(implode("\n", [
        '## Relationships',
        '',
        '- **controller**: App\\Http\\Controllers\\OrderController',
        '- **uses_middleware**: auth',
        '- **validates_with**: [App\\Http\\Requests\\StoreOrderRequest](/artifacts/store-order-request.md)',
        '- **authorized_by**: create (create)',
        '- **tested_by**: tests:tests/Feature/OrderTest.php',
        '',
    ]));
});

test('build() ignores Relationships of in-scope types that start from another artifact', function () {
    $route = ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'source' => null];
    $relationships = [
        new Relationship('routes:GET:other', RelationshipType::UsesMiddleware, 'auth', [Provenance::Runtime], false, ['groups' => [], 'direct' => true]),
    ];

    $concept = buildConceptFromRelationships('routes', $route, $relationships);

    expect($concept->content)->not->toContain('## Relationships');
});
