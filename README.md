<p align="center">                                                                                                                                                                
  <img src="docs/banner.png" alt="Laravel Necromancer" width="100%">                                                                                                              
</p>

Laravel Necromancer scans your bootstrapped Laravel application and builds a structured, machine-readable inventory called the **manifest**. From that manifest you can display a terminal map of your application, run an AI-readability audit, and generate a Markdown context file that AI coding agents can load as ambient context — so they always have an accurate picture of your routes, models, actions, jobs, events, observers, scheduled tasks, middleware, Livewire components, gates, mailables, validation rules, service providers, and more.

## Contents

- [What Necromancer Collects](#what-necromancer-collects)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Step 1 — Scan](#step-1--scan)
  - [Step 2 — Explore (optional)](#step-2--explore-optional)
  - [Step 3a — Audit AI readability](#step-3a--audit-ai-readability)
  - [Step 3b — Check the AI readability score](#step-3b--check-the-ai-readability-score)
  - [Step 3c — Generate AI context](#step-3c--generate-ai-context)
  - [Step 3d — Ask a question about your codebase](#step-3d--ask-a-question-about-your-codebase)
    - [Inspect the AI payload](#inspect-the-ai-payload)
  - [Step 3e — Infer Architecture Decision Records](#step-3e--infer-architecture-decision-records)
  - [Step 3f — Generate a source-grounded prompt](#step-3f--generate-a-source-grounded-prompt)
  - [Step 3g — Compare manifests across branches](#step-3g--compare-manifests-across-branches)
  - [Step 3h — Benchmark AI context effectiveness](#step-3h--benchmark-ai-context-effectiveness)
  - [Step 3i — Export an OKF Knowledge Bundle](#step-3i--export-an-okf-knowledge-bundle)
  - [Step 3j — Generate an AI-Enriched Knowledge Bundle](#step-3j--generate-an-ai-enriched-knowledge-bundle)
  - [Step 3k — Visualize the Artifact Graph](#step-3k--visualize-the-artifact-graph)
  - [Step 3l — Analyze an artifact's Impact](#step-3l--analyze-an-artifacts-impact)
  - [Step 3m — Find the tests affected by a change](#step-3m--find-the-tests-affected-by-a-change)
- [Commands Reference](#commands-reference)
- [Configuration](#configuration)
- [Privacy & Exclusions](#privacy--exclusions)
- [Laravel Boost Integration](#laravel-boost-integration)
- [MCP Tools](#mcp-tools)
- [CI Integration](#ci-integration)
- [Upgrading to 2.0](#upgrading-to-20)
- [Contributing](#contributing)
- [License](#license)

## What Necromancer Collects

The manifest covers 20 artifact types across the full Laravel application structure:

| Type | What it surfaces |
|---|---|
| `routes` | Name, method, URI, controller, action, middleware, authorization, route metadata (domain, flow, capability, summary, risk, external services, ADR) |
| `controllers` | Controller Actions with parameter and return types, declared middleware, and the routes targeting each |
| `models` | Table, fillable, casts, appends, relationships, scopes, observers, policy, factory |
| `jobs` | Queue, connection, tries, timeout, backoff, max_exceptions |
| `events` | Listeners, broadcastable channels |
| `listeners` | Handled events, queued status |
| `commands` | Signature, description, aliases |
| `form_requests` | Rules, stop_on_first_failure, error_bag |
| `actions` | Entrypoint methods with parameter and return types (classes under `app/Actions`) |
| `policies` | Model, policy methods |
| `enums` | Backing type, cases |
| `tests` | File, type (unit/feature), subject class, test methods, referenced app classes and route names |
| `observers` | Model, lifecycle hooks, queued status |
| `scheduled_tasks` | Command, cron expression, human-readable schedule, flags |
| `middleware` | Alias, class, scope (global/group/alias), group name |
| `livewire_components` | View, public properties with types, action methods, listened events |
| `gates` | Ability, kind (closure/class/before_hook/after_hook), parameters |
| `mailables` | Subject, queued status, queue name, view/markdown template |
| `validation_rules` | Implicit flag, docblock description |
| `service_providers` | Deferred flag, source location |

Every class-backed type can also carry a `dispatches` field listing the jobs, events, and mailables it dispatches — see [Dispatches](#dispatches) below.

All artifact types carry a `source` field with `file`, `line`, `line_end`, and `hash` for precise citations and stale detection.

Every class-backed type in the table above — `controllers`, `models`, `form_requests`, `actions`, `jobs`, `events`, `listeners`, `commands`, `policies`, `enums`, `observers`, `livewire_components`, `mailables`, `validation_rules`, `service_providers` — plus `middleware` and route controllers/actions can also carry a declared `annotations` block (`domain`, `flow`, `capability`, `summary`, `risk`, `external_services`, `adrs`) via the `#[Necromancer]` attribute. See [Annotating class-backed artifacts, controllers, and middleware](#annotating-class-backed-artifacts-controllers-and-middleware) below. `gates`, `tests`, and `scheduled_tasks` — plus registration-specific overrides for every other type — are annotated instead through exact-ID mappings in configuration. See [Annotating non-reflectable artifacts with exact-ID mappings](#annotating-non-reflectable-artifacts-with-exact-id-mappings) below.

## Requirements

| | Version |
|---|---|
| PHP | ≥ 8.3 |
| Laravel | 13.x |

## Installation

Install the package as a development dependency:

```bash
composer require --dev robertogallea/laravel-necromancer
```

The service provider is auto-discovered — no manual registration is needed.

Optionally publish the configuration file:

```bash
php artisan vendor:publish --tag=necromancer-config
```

## Usage

Necromancer follows a **scan-first** workflow. Every other command reads the manifest produced by `necromancer:scan` rather than re-scanning the application.

### Step 1 — Scan

```bash
php artisan necromancer:scan
```

Inspects the running application and writes `necromancer.json` to the project root. Re-run this command whenever your application changes. Collect only specific artifact types with `--only`:

```bash
php artisan necromancer:scan --only=routes,models
php artisan necromancer:scan --only=observers,scheduled_tasks,gates
```

Necromancer reads PHP attributes (`#[ObservedBy]`, `#[Queue]`, `#[Aliases]`, `#[Authorize]`, etc.) as primary sources alongside class properties. Codebases using the attribute-based API introduced in Laravel 11+ are fully supported — jobs configured via `#[Queue]`/`#[Tries]`/`#[Timeout]`, models with `#[ObservedBy]`/`#[ScopedBy]`, and commands with `#[Aliases]` all appear correctly in the manifest.

Test files in `tests/Unit/` and `tests/Feature/` are scanned and included as a `tests` artifact type. Both Pest functional-style files (`test()`/`it()` calls) and class-based PHPUnit tests are supported. Subject classes are inferred from `uses()` declarations and filename convention (`OrderTest.php` → `App\Models\Order`).

Each test also records what its source references, so a feature test whose filename matches no class still connects to what it exercises:

```php
use App\Models\Order;

it('creates an order', function () {
    $this->post(route('orders.store'))->assertCreated();

    expect(Order::query()->count())->toBe(1);
});
```

```json
"references": [
    { "kind": "class", "target": "App\\Models\\Order" },
    { "kind": "route", "target": "orders.store" }
]
```

A `class` reference is an application-namespace class the test uses in code — `X::class`, `new X(...)`, or `X::method(...)`, resolved through the file's imports. An import the code never uses doesn't count, and neither do framework or vendor classes such as `Mail`. A `route` reference is a literal name passed to `route()`; `route($name)` and URIs passed to `$this->get('/orders')` are not seen. Entries are deduplicated and sorted by kind, then target, and a test referencing nothing has no `references` key. Each reference becomes a `tested_by` [Relationship](#relationships) marked `match: reference`. A test file that can't be parsed gets no `references`, and the scan prints a non-fatal `TR_PARSE_FAILED` diagnostic.

Action classes — single-purpose classes that encapsulate one business operation — are collected from `app/Actions` (including subfolders) as an `actions` artifact type. Any concrete class there with at least one **entrypoint** (a public, non-static method declared on the class itself; `__invoke` counts, other magic methods and inherited methods don't) is included, with its entrypoints' parameter names/types and return type:

```php
#[Necromancer(domain: 'orders', flow: 'order-cancellation', risk: Risk::High)]
final class CancelOrder
{
    public function handle(Order $order, User $actor): Order { /* ... */ }
}
```

```json
{
    "id": "actions:App\\Actions\\CancelOrder",
    "class": "App\\Actions\\CancelOrder",
    "entrypoints": [
        {
            "name": "handle",
            "parameters": [
                { "name": "order", "type": "App\\Models\\Order" },
                { "name": "actor", "type": "App\\Models\\User" }
            ],
            "return_type": "App\\Models\\Order"
        }
    ],
    "annotations": { "domain": "orders", "flow": "order-cancellation", "risk": "high" }
}
```

Classes under `app/Actions` with no entrypoint (DTOs, exceptions, helpers), abstract classes, interfaces, traits, and enums are skipped. Only a class-level `#[Necromancer]` applies to an Action — a method-level attribute on an entrypoint is ignored. The class types an Action's entrypoints accept become an `operates_on` relationship in the OKF bundle and the Artifact Graph. Only `app/Actions` is scanned: Actions living elsewhere (e.g. `app/Domain/*/Actions`) are not discovered.

Controllers are collected as a `controllers` artifact type — one artifact per controller class, from `app/Http/Controllers` (including subfolders) plus any application-namespace controller a route targets from elsewhere (e.g. `app/Domain/Billing/Http/InvoiceController`). Each **Controller Action** — a public, non-static method written in the controller itself (`__invoke` counts, other magic methods don't), plus any inherited or trait method a route targets — records its parameters, return type, the middleware the controller itself declares for it, and the Artifact IDs of the routes that target it:

```php
#[Necromancer(domain: 'orders')]
final class OrderController implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['auth', new Middleware('verified', only: ['store'])];
    }

    public function store(StoreOrderRequest $request): RedirectResponse { /* ... */ }
}
```

```json
{
    "id": "controllers:App\\Http\\Controllers\\OrderController",
    "class": "App\\Http\\Controllers\\OrderController",
    "actions": [
        {
            "name": "store",
            "parameters": [{ "name": "request", "type": "App\\Http\\Requests\\StoreOrderRequest" }],
            "return_type": "Illuminate\\Http\\RedirectResponse",
            "middleware": ["auth", "verified"],
            "routes": ["routes:POST:orders"]
        }
    ],
    "annotations": { "domain": "orders" }
}
```

An action no route targets has `routes: []`. Routes removed by `exclude.routes`/`exclude.route_uris` are never listed, and never pull a controller into the manifest. Vendor controllers (e.g. Laravel's `RedirectController`), abstract controllers, and classes with no Controller Action are skipped.

Action middleware comes from the controller's own declarations — `HasMiddleware::middleware()` and `#[Middleware]`/`#[WithoutMiddleware]` attributes, honoring `only`/`except` — and is read without ever instantiating the controller. Middleware registered with `$this->middleware()` in a constructor is therefore not visible here; the route artifact's `middleware` list, which also carries group middleware, still includes it.

A controller's `annotations` come from its class-level `#[Necromancer]` attribute (and exact-ID config mappings). A method-level `#[Necromancer]` keeps refining the annotations of the routes that target that method, as described below, and is not copied onto the controller artifact. A route's `controller` relationship resolves to the controller's concept in the OKF bundle and its node in the Artifact Graph.

#### Dispatches

A dispatch — handing a job to the bus, firing an event, or sending a mailable — only exists inside a method body, so it's the one fact Necromancer reads from source text rather than from the booted application. For every class-backed artifact the scan collected (controllers, models, actions, jobs, events, listeners, commands, policies, form requests, enums, observers, Livewire components, mailables, validation rules, service providers, and class-backed middleware), it parses the artifact's own file with `nikic/php-parser` and records what each method dispatches:

```php
final class PlaceOrder
{
    public function handle(Order $order): void
    {
        SendInvoice::dispatch($order);
        event(new OrderPlaced($order));
    }
}
```

```json
"dispatches": [
    { "target": "App\\Events\\OrderPlaced", "method": "handle", "mode": null },
    { "target": "App\\Jobs\\SendInvoice", "method": "handle", "mode": "queued" }
]
```

`target` is the fully-qualified class, resolved through the file's `use` imports (aliases included). `mode` records which API the call went through, not whether the target actually queues — that's the target's own fact:

| Call shape | `mode` |
|---|---|
| `dispatch(new X)`, `X::dispatch(...)`, `X::dispatchIf/dispatchUnless(...)`, `Bus::dispatch(new X)`, `$this->dispatch(new X)` | `queued` |
| `dispatch_sync(new X)`, `X::dispatchSync(...)`, `Bus::dispatchSync(new X)` | `sync` |
| `Bus::chain([new A, new B])`, `Bus::batch([...])` — one entry per class | `queued` |
| `event(new X)`, `Event::dispatch(new X)`, `broadcast(new X)` | `null` |
| `Mail::send(new X)`, `Mail::to(...)->send(new X)` | `sync` |
| `Mail::to(...)->queue(new X)`, `Mail::to(...)->later(..., new X)` | `queued` |

Entries are sorted by method then target, deduplicated, and carry no line numbers; an artifact that dispatches nothing has no `dispatches` key. Each dispatched target becomes a `dispatches` [Relationship](#relationships). In the OKF bundle the field appears among an Artifact Concept's Discovered Facts like any other fact; it isn't yet rendered as a link in its `## Relationships` section.

Limitations:

- Only targets written as a class name (`new X(...)` or `X::...`) are seen. Dynamic targets (`dispatch($job)`, container-resolved classes), string events (`event('order.placed')`, Livewire's `$this->dispatch('browser-event')`), and queued closures are skipped.
- Only methods written in the artifact's own file are read: a dispatch in a parent class or a trait is not attributed to the child.
- A dispatch written inside a closure or an anonymous class is credited to the method that contains it — this is how `static::created(fn () => event(new OrderPlaced))` in a model's `booted()` is recorded.
- Only the shapes in the table are recognized: a dispatch through an injected dispatcher (`$this->bus->dispatch(new X)`), `Mail::queue(new X)` called directly on the facade, or a custom facade wrapping the bus is not seen. A custom facade's `MyBus::dispatch(...)` reads as a dispatch of `MyBus` itself.
- Closure-based dispatchers (closure routes, scheduled closures, closure gates) have no class to read and are not covered. Notifications are not tracked.
- If an artifact's file can't be parsed, the scan still succeeds, that artifact gets no `dispatches`, and a non-fatal `DS_PARSE_FAILED` diagnostic names it.

On Laravel 13.17+, routes using the native [`Route::metadata()`](https://laravel.com/docs/routing#route-metadata) API are scanned too. Necromancer reads a reserved `necromancer` namespace within that metadata as a compact, declared-by-the-developer semantic signal — separate from anything Necromancer infers itself. The `withNecromancer()` route macro declares it:

```php
Route::post('/billing/cancel', [SubscriptionController::class, 'cancel'])
    ->withNecromancer(
        domain: 'billing',
        flow: 'subscription-cancellation',
        capability: 'subscription.cancel',
        summary: 'Cancels an active subscription.',
        risk: 'high',
        externalServices: ['stripe'],
        adrs: ['docs/adr/004-subscription-cancellation.md'],
    );
```

Every field is an optional named argument, and `externalServices` accepts either a single string or an array of strings. The macro is registered on every routing surface, so a whole group — or every route a resource registers — can be tagged in one place:

```php
// Group position, before or after any other group attribute
Route::withNecromancer(domain: 'billing')->prefix('billing')->group(/* ... */);
Route::prefix('billing')->withNecromancer(domain: 'billing')->group(/* ... */);

// Resource and singleton registrations
Route::resource('posts', PostController::class)->withNecromancer(domain: 'blog');
Route::singleton('profile', ProfileController::class)->withNecromancer(domain: 'account');
```

Routes inherit the fields declared by their group, and a field set on the route itself wins over the group's value for that field — Laravel's own route metadata merging, not a Necromancer behaviour.

The macro is a shorthand, never a parallel metadata system: it wraps the arguments you pass under the configured `route_metadata.namespace`, drops the ones left null, and hands the result to native `->metadata()`. The equivalent raw array is always supported, and is the form to use on Laravel < 13.17 — where `withNecromancer()` throws, since the framework has no route metadata to write to:

```php
Route::post('/billing/cancel', [SubscriptionController::class, 'cancel'])
    ->metadata([
        'necromancer' => [
            'domain' => 'billing',
            'risk' => 'high',
            'external_services' => ['stripe'],
        ],
    ]);
```

All fields are optional and the feature is entirely opt-in — apps that don't declare route metadata, or that run Laravel < 13.17, are unaffected; the `route_metadata` key is simply omitted from the manifest. Keep values compact (labels, identifiers, ADR references) rather than long narrative descriptions — ADRs, domain docs, and the generated context file remain the right place for extended architectural explanations. This declared metadata takes priority over any naming/namespace-based inference Necromancer performs, and — resolved alongside annotations declared on every other artifact family — is used by `necromancer:doctor` (Artifact Annotation Coverage scoring), `necromancer:audit` (quality checks), `necromancer:generate` (route table columns and the Architectural Context column on every other section), and `necromancer:diff` (flagged high-risk/external-service artifacts) — see each command's section below.

#### Annotating class-backed artifacts, controllers, and middleware

The same `domain`/`flow`/`capability`/`summary`/`risk`/`externalServices`/`adrs` fields can be declared directly on a class or method with the `#[Necromancer]` attribute, so artifacts that aren't routes get the same declared-intent signal:

```php
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Metadata\Risk;

#[Necromancer(domain: 'billing', capability: 'invoice.send', risk: Risk::High, externalServices: ['stripe'])]
final class SendInvoiceEmail implements ShouldQueue
{
    // ...
}
```

The attribute is a single, non-repeatable declaration and applies directly to every class-backed artifact type: models, form requests, actions, jobs, events, listeners, commands, policies, enums, observers, Livewire components, mailables, validation rules, and service providers.

On a controller, a class-level attribute supplies defaults for every action, and a method-level attribute refines them — the action wins for any field it declares, silently, with no warning:

```php
#[Necromancer(domain: 'billing', risk: Risk::Low)]
final class SubscriptionController
{
    #[Necromancer(capability: 'subscription.cancel', risk: Risk::High)]
    public function cancel(): RedirectResponse { /* ... */ }
}
```

`cancel()`'s route annotations resolve to `domain: billing` (inherited), `capability: subscription.cancel`, and `risk: high` (refined). Native `Route::metadata()` — including the `withNecromancer()` macro — remains the most specific declaration and overrides a conflicting controller-derived value; when it does, `necromancer:scan` prints an `AN_SOURCE_CONFLICT` warning naming the field so the disagreement isn't silent.

A middleware class annotation applies to every place that middleware is registered — globally, in a group, or under an alias each produce their own manifest entry, but all of them carry the same annotations:

```php
#[Necromancer(domain: 'security', risk: Risk::High)]
final class EnsureTwoFactorIsEnabled
{
    // ...
}
```

#### Annotating non-reflectable artifacts with exact-ID mappings

Closures, test files, gates, and scheduled tasks have no class or method to carry a `#[Necromancer]` attribute. The `annotations` key in `config/necromancer.php` covers these — and adds a registration-specific override on top of any other family, including middleware — by mapping an exact, opaque canonical Artifact ID (the same `id` every artifact already carries in the manifest) to a Schema v1 field array:

```php
'annotations' => [
    'gates:ability:edit-post' => [
        'domain' => 'content',
        'risk' => 'low',
    ],
    'middleware:group:web:App\\Http\\Middleware\\EnsureTwoFactorIsEnabled' => [
        'capability' => 'security.two-factor',
    ],
],
```

Keys must be exact IDs — there is no wildcard or pattern syntax, and an unresolvable made-up ID is never allowed to invent an artifact. A mapping only **fills** an annotation field left absent by every other declaration source; when it disagrees with an already-resolved value (a `#[Necromancer]` attribute, a controller annotation, or native route metadata), the existing value wins and `necromancer:scan` prints an `AN_SOURCE_CONFLICT` warning. List fields (`external_services`, `adrs`) are additive: config values are appended after whatever was already resolved, with exact deduplication. An unknown field name, an empty scalar, an invalid `risk` value, or a wildcard/malformed key fails the scan before anything is written, leaving an existing manifest untouched — the same controlled-failure behavior invalid `#[Necromancer]` attribute values already produce. A mapping whose artifact type is included in the current scan but whose exact ID matches nothing collected prints a non-fatal `AN_CONFIG_UNMATCHED` warning; a mapping for a type outside a `--only` scan's scope is silently skipped.

Check for manifest drift without writing a new file (CI use):

```bash
php artisan necromancer:scan --diff                         # show added/removed/changed artifacts
php artisan necromancer:scan --diff --fail-on-drift        # exit 1 when drift detected
```

Drift means the freshly scanned manifest's `meta.content_hash` differs from the one on disk. A manifest with no `content_hash` is compared artifact by artifact instead. Any change to an existing artifact counts, including an edit to its source file that only changes the recorded `source.hash`, such as reformatting. Changed artifacts are listed with `~`. If the hashes differ but no individual artifact changed (for example, after a schema or hashing change between Necromancer versions), the command says so and still treats it as drift.

### Step 2 — Explore (optional)

Display the full application inventory in the terminal:

```bash
php artisan necromancer:map
```

Narrow the output to a single artifact type:

```bash
php artisan necromancer:map --type=routes
php artisan necromancer:map --type=models
```

### Step 3a — Audit AI readability

Check how well your application can be understood by an AI coding agent:

```bash
php artisan necromancer:audit
```

Each finding is grouped by severity (error / warning / suggestion). The score is a weighted pass-rate across all checks — normalized by the number of applicable artifacts — so an app with 1 unnamed route out of 50 scores far better than one with 1 out of 1. Errors weigh 3×, warnings 2×, and suggestions 1× in the calculation. Any artifact carrying declared Artifact Annotations — not just routes — is checked for quality: a `risk: high`/`critical` artifact with no `adrs` reference, an `external_services` artifact with no matching test subject, a `summary` over 200 characters (narrative content that belongs in an ADR instead), artifacts sharing the same `flow` that disagree on `domain` or `risk` (a single business process should agree on both), non-canonical or near-duplicate `domain`/`flow`/`capability`/`external_services` spelling, and `adrs` entries pointing at a local file that doesn't exist all produce findings — but only for artifacts that have actually declared annotations, so adopting the feature is never required to keep a clean audit. Output a shareable or machine-readable report, or enforce a CI gate:

```bash
php artisan necromancer:audit --format=markdown              # paste into a GitHub issue or PR
php artisan necromancer:audit --format=markdown --output=audit.md
php artisan necromancer:audit --format=json --output=audit.json
php artisan necromancer:audit --fail-on=error    # exit 1 if any errors (CI use)
php artisan necromancer:audit --fail-on=warning  # exit 1 if any warnings or errors
```

### Step 3b — Check the AI readability score

Get a quick percentage score across eight weighted dimensions of AI readability:

```bash
php artisan necromancer:doctor
```

Each dimension shows a progress bar, a percentage, and a detail line:

```
  Laravel Necromancer — AI Readability Score
  ──────────────────────────────────────────
  Score: 74%

  Route Clarity             ████████░░  82%  (12/15 named · 14/15 controller-backed)
  Model Expressiveness      ██████░░░░  61%  (3/5 casts · 4/5 fillable · 2/5 relationships)
  Authorization Coverage    ███████░░░  70%  (2/3 policies · 8/12 write routes with auth)
  Validation Coverage       ████████░░  80%  (8/10 write routes with FormRequest)
  Async Clarity             ████████░░  83%  (4/5 jobs configured · 4/4 events with listeners)
  Codebase Vocabulary       ██████░░░░  63%  (5/8 commands described · 1/1 backed enums)
  Test Presence             ████████░░  80%  (4/5 models · 3/3 jobs · 2/3 actions)
  Artifact Annotation Cov.  ████████░░  83%  (5/6 tagged with domain · 2/2 high-risk with ADR · 1/2 external-service artifacts tested · 4/4 flow-consistent)

  Tip: run necromancer:audit for a detailed findings list.
```

Artifact Annotation Coverage scores N/A (and doesn't affect the overall score) until at least one artifact of any family declares Artifact Annotations — adopting the feature is entirely optional. Its emitted dimension key is `artifact-annotation-coverage`; pass it to `--only` to score just this dimension.

Output a machine-readable score or enforce a CI gate:

```bash
php artisan necromancer:doctor --json
php artisan necromancer:doctor --min-score=80        # exit 1 when score < 80 (CI use)
php artisan necromancer:doctor --only=route-clarity  # score a single dimension
```

### Step 3c — Generate AI context

Write a Markdown context file your AI tool can load:

```bash
php artisan necromancer:generate
```

Produces `NECROMANCER.md` at the project root. The generated file includes a `## Tests` table when test artifacts are present:

```markdown
## Tests (12)
| File | Type | Subject | Tests |
|---|---|---|---|
| tests/Unit/Models/OrderTest.php | unit | Order | it creates an order, it calculates total |
| tests/Feature/OrderCheckoutTest.php | feature | | test_it_completes_checkout |
```

Routes render their Domain/Risk/External Services/ADR columns from resolved Artifact Annotations (declared via route metadata, a `#[Necromancer]` attribute, or an exact-ID configuration mapping). Every other artifact section — models, jobs, events, and the rest — renders a single compact `Architectural Context` column whenever at least one of its artifacts declares annotations, so developer intent stays visible without a dedicated column per field:

```markdown
## Jobs (1)
| Name | Queue | Connection | Tries | Architectural Context |
|---|---|---|---|---|
| SyncStripeInvoices | billing | redis | 3 | domain: billing · risk: high · external services: stripe |
```

The column is omitted entirely for a section where no artifact declares annotations.

If a Knowledge Bundle exists at its configured default path (`okf/`, `okf-enriched/`, or wherever `okf.output`/`okf.enrichment.output` point), a `## Knowledge Bundle` section names it, its regenerate command, and its live stats — in `NECROMANCER.md` and in the compact `CLAUDE.md`/`AGENTS.md` output alike:

```markdown
## Knowledge Bundle

- **okf/** — 12 artifact concepts, generated 2026-08-07T12:00:00+02:00. Regenerate with `php artisan necromancer:okf`.
```

The section is exempt from `--only`/`--except`/`--paths` (the same way the Application header always is) and is omitted entirely when no bundle exists at either configured path. A bundle exported to a custom `--output` path is not detected — only the configured defaults are checked. Set `okf.announce_in_context` to `false` in `config/necromancer.php` to suppress the section outright.

Each line compares that bundle's own `content_hash` against the current manifest's `content_hash` and appends a caveat when they differ:

```markdown
- **okf/** — 12 artifact concepts, generated 2026-08-07T12:00:00+02:00. Regenerate with `php artisan necromancer:okf`. ⚠ May be stale relative to the current manifest — re-run `php artisan necromancer:okf`.
```

A bundle exported before this feature existed (no `content_hash` key at all in its `bundle.json`) renders with no staleness claim either way — never assumed stale or fresh.

Generate only specific sections:

```bash
php artisan necromancer:generate --only=routes,models
php artisan necromancer:generate --only=observers,scheduled_tasks
php artisan necromancer:generate --only=gates,middleware,mailables
```

Supported types: `routes`, `controllers`, `models`, `form_requests`, `actions`, `jobs`, `events`, `listeners`, `commands`, `policies`, `enums`, `tests`, `observers`, `scheduled_tasks`, `middleware`, `livewire_components`, `gates`, `mailables`, `validation_rules`, `service_providers`.

Exclude specific sections instead of listing everything you want:

```bash
php artisan necromancer:generate --except=listeners
php artisan necromancer:generate --except=listeners,validation_rules,service_providers
```

`--only` and `--except` are mutually exclusive.

Filter by source path to generate context for just one slice of the application:

```bash
php artisan necromancer:generate --paths=app/Models,app/Http/Controllers/Admin
php artisan necromancer:generate --only=models --paths=app/Models
```

`--paths` matches each artifact's `source.file` by path prefix (after normalizing slashes) and is applied on top of `--only`/`--except`, so it can be combined with either. Paths are case-sensitive on Linux and case-insensitive on macOS/Windows, following the filesystem. Artifacts without a source file (closure routes, inline gates) are excluded while `--paths` is active, sections that end up empty are omitted, and a path that matches nothing emits a warning without failing.

Skip the overwrite confirmation when regenerating:

```bash
php artisan necromancer:generate --force
```

Write to a custom path:

```bash
php artisan necromancer:generate --output=.ai/context/app.md
```

### Step 3d — Ask a question about your codebase

Ask a natural-language question and get a grounded answer from your manifest:

```bash
php artisan necromancer:ask "What routes require authentication?"
```

If you omit the question, the command prompts you interactively. The manifest is injected verbatim into the AI's context, so answers are grounded in your actual application — not a model's prior knowledge. A warning is shown if the manifest may be stale.

The full manifest is always included — nothing is discarded — but a "Most Relevant Evidence" section is prepended ahead of it, ranking the artifacts most related to your question so the AI's attention is prioritized rather than left to search the whole payload unguided. Ranking uses the same keyword scoring as `necromancer:prompt`, boosted for declared Artifact Annotations on any artifact family: a `domain`/`flow`/`capability` counts as strongly as its name or class, since that's an intentional signal from the developer rather than something inferred from naming.

```bash
php artisan necromancer:ask                                        # interactive prompt
php artisan necromancer:ask "..." --provider=anthropic             # provider override
php artisan necromancer:ask "..." --model=claude-sonnet-4-5        # model override
```

> **Requires** `laravel/ai` installed and an AI provider configured in `config/ai.php`.

### Inspect the AI payload

Before committing to a provider, check exactly what Necromancer sends to the AI and how large the payload is:

```bash
php artisan necromancer:inspect-payload
```

Prints the full manifest JSON, the estimated token count, and a breakdown of artifact type counts. Pass `--privacy` to see the condensed privacy-safe summary instead (the payload used when a privacy-conscious provider is configured):

```bash
php artisan necromancer:inspect-payload --privacy
```

---

### Step 3e — Infer Architecture Decision Records

Generate [Architecture Decision Records](https://adr.github.io/) from the manifest using an AI provider:

```bash
php artisan necromancer:infer
```

> **Requires** `laravel/ai` installed and an AI provider configured in `config/ai.php`.

#### Options

| Option | Description |
|---|---|
| `--locale=it` | Translate ADRs into additional locales (comma-separated, e.g. `--locale=it,fr`). The default app locale is always inferred first; extra locales are translated from it. |
| `--temperature=0` | LLM temperature (0.0–2.0). Lower values produce more deterministic output. Omit to use the provider default. |
| `--max-critic-rounds=N` | Maximum number of critic review rounds (default 1). The loop exits early if the critic is satisfied before reaching N. |
| `--dry-run` | Print ADRs to the terminal without writing files. |
| `--force` | Overwrite existing ADR files without confirmation. |
| `--fresh` | Delete all existing ADR files and the inference cache, then re-infer from scratch. |
| `--refresh` | Bypass the cache and re-infer even if the manifest has not changed. |

#### Output

ADRs are written to `docs/adr/necromancer/` (canonical locale, flat) and `docs/adr/necromancer/{locale}/` for each translated locale. Each file follows the [Nygard ADR format](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions):

```markdown
# ADR 0001: Async Email Delivery via Dedicated Queue

**Status:** Inferred
**Dimension:** Async Processing
**Confidence:** High
**Date:** 2026-05-29

## Context
...

## Decision
...

## Consequences
...

## Counter-Evidence
No contradicting evidence found in the manifest.
```

#### Decision Taxonomy

The inference agent evaluates nine architectural dimensions and produces at most one ADR per dimension:

| Dimension | What it covers |
|---|---|
| `async-processing` | Jobs, queues, workers, retry strategy |
| `authorization` | Policies, gates, middleware auth guards |
| `event-driven` | Events, listeners, broadcasting |
| `api-design` | Route structure, API resources, versioning |
| `data-modeling` | Model relationships, casts, soft deletes |
| `command-scheduling` | Artisan commands, scheduled tasks |
| `form-validation` | Form requests vs inline validation |
| `external-services` | Mail, storage, payment, third-party services |
| `architecture-pattern` | Service layer, repository, MVC deviations |

#### Critic Agent

By default, a second AI pass reviews and filters the initial ADRs, removing generic observations and improving specificity. Configure via `config/necromancer.php`:

```php
'inference' => [
    'critic' => [
        'enabled' => true,   // set to false to disable the critic entirely
    ],
],
```

Use `--max-critic-rounds=N` to run up to N review passes. The critic signals when it is satisfied; the loop exits early even if N has not been reached. Each unsatisfied round adds one AI call.

```bash
# Two critic rounds at most (exits early if satisfied after round 1)
php artisan necromancer:infer --temperature=0 --max-critic-rounds=2
```

#### Caching

The command caches inference results in `docs/adr/necromancer/.adr-inference-cache.json`. The cache key is derived from `meta.content_hash` (a SHA-256 of the artifact payload written into every manifest by `necromancer:scan`), plus the temperature and critic settings. Because the hash covers only artifact data — not the scan timestamp — the cache survives re-scans that find no structural changes. On subsequent runs:

- **Unchanged codebase** — cached ADRs are used even if `necromancer:scan` was re-run; no AI call.
- **New `--locale` added** — only the translation call is made; inference is skipped.
- **`--refresh`** — forces re-inference with an unchanged manifest.
- **`--fresh`** — clears the cache and all ADR files before re-inferring.

#### Multi-Locale Workflow

```bash
# Generate canonical ADRs in the app locale (config('app.locale'))
php artisan necromancer:infer --temperature=0

# Add Italian translation without re-running inference
php artisan necromancer:infer --temperature=0 --locale=it

# Regenerate everything from scratch
php artisan necromancer:infer --temperature=0 --fresh
```

---

### Step 3f — Generate a source-grounded prompt

Build a ready-to-paste AI prompt grounded in the most relevant manifest entries for your question:

```bash
php artisan necromancer:prompt "Where is tenant isolation enforced?"
```

Necromancer keyword-searches the manifest, ranks artifacts by relevance, and outputs a formatted prompt block with `file:line` citations that you can paste into any AI tool (Claude, ChatGPT, Cursor, etc.).

```
You are analyzing MyApp, a Laravel 13 application.

Use the following source-grounded manifest entries:
- app/Http/Middleware/SetTenant.php:15-61
- app/Models/Project.php:12-44
- app/Http/Requests/CreateProjectRequest.php:1-38

Question:
Where is tenant isolation enforced?

Rules:
- Only answer from cited sources.
- Mention missing evidence.
- Do not assume runtime behavior not shown in code/tests.
```

If `laravel/ai` is installed, the question is automatically reformulated into a precise, application-aware version before being included in the prompt. Pass `--no-ai` to use the raw question instead.

```bash
php artisan necromancer:prompt "authentication" --no-ai    # skip AI reformulation
php artisan necromancer:prompt "billing" --top=5           # limit to 5 citations
php artisan necromancer:prompt "auth" --output=prompt.txt  # write to file
php artisan necromancer:prompt                             # interactive question prompt
```

> **AI reformulation** requires `laravel/ai` installed and configured. The command works without it — only the question contextualization step is skipped.

---

### Step 3g — Compare manifests across branches

Review the architectural changes introduced by a branch compared to another:

```bash
php artisan necromancer:diff main
```

Compares the current manifest against the manifest on the `main` branch. The output shows added, removed, and modified routes, models, jobs, events, listeners, policies, and other artifacts.

When an added or changed artifact of any family — not just routes — declares `risk: high`/`critical` or a non-empty `external_services` via its resolved Artifact Annotations, it's called out in a dedicated "Flagged Artifacts" section before the rest of the diff — this is a deterministic check, so it shows up even without `--review`/`laravel/ai`. Each flagged artifact also shows its `domain`, `flow`, and `capability` when declared, so reviewers see business context alongside the trigger:

```text
FLAGGED ARTIFACTS
⚠  routes  POST /billing/cancel (billing.cancel)  domain: billing · flow: subscription-cancellation · capability: subscription.cancel · risk: high
```

#### Options

| Option | Description |
|---|---|
| `--base-manifest=PATH` | Use a specific manifest file instead of comparing branches. Useful for comparing against a snapshot. |
| `--review` | Enable AI-powered analysis of the changes (requires `laravel/ai`). Produces a narrative summary of the architectural impact, detected risks, and suggested reviewer questions. |
| `--format=markdown` | Output in Markdown format (suitable for pasting into PRs or issues). |
| `--output=PATH` | Write the report to a file instead of printing to the terminal. |

#### Example: Basic diff

```bash
php artisan necromancer:diff main
```

Output:

```text
Comparing current manifest to main branch

Added:
  - Route: POST /api/subscriptions (SubscriptionController@store)
  - Model: App\Models\Subscription
  - Event: App\Events\SubscriptionCreated
  - Listener: App\Listeners\SendSubscriptionEmail (queued)
  - Job: App\Jobs\ProcessSubscriptionActivation

Modified:
  - Route: GET /dashboard (Dashboard parameter added)
  - Model: User (new cast: subscription_tier)

Removed:
  - Policy: App\Policies\TrialPolicy
```

#### Example: AI-powered review

```bash
php artisan necromancer:diff main --review --format=markdown
```

Output (when `laravel/ai` is installed):

```markdown
## Architectural Changes

This PR introduces a subscription model with activation workflow.

### Evidence
- New listener: SendSubscriptionEmail (queued)
- New job: ProcessSubscriptionActivation
- New event: SubscriptionCreated
- Modified route: GET /dashboard (dashboard parameter added)

### Risks
- No failed-activation test detected.
- No policy for Subscription model.
- Job retry strategy not configured.

### Suggested Reviewer Questions
1. Should subscription activation be idempotent?
2. What happens if the activation job fails multiple times?
3. Is SendSubscriptionEmail queued appropriately?
```

> **Note:** The `--review` option requires `laravel/ai` to be installed and configured. Without it, only the basic diff is shown. Both branches must have a committed `necromancer.json` manifest.

The AI reviewer's prompt includes the same "Flagged Artifacts" signal shown in the deterministic diff (high/critical-risk and external-service artifacts of any family, with `domain`/`flow`/`capability` when declared) — so its risk assessment is grounded in what you actually declared via Artifact Annotations, not left to infer risk purely from the raw diff.

---

### Step 3h — Benchmark AI context effectiveness

Measure how much Necromancer's generated context file improves AI coding-assistant accuracy, hallucination rate, latency, and token cost compared to a hand-written `AGENTS.md` or no context at all:

```bash
php artisan necromancer:benchmark
```

The command runs a bundled task suite in three conditions by default — no context, manual `AGENTS.md`, and Necromancer-generated `NECROMANCER.md` — and reports the results side by side. An opt-in fourth condition, `necromancer-mcp`, runs the model with bare instructions plus live, tool-based access to the same route/model/artifact/search queries the MCP server exposes, rather than a pre-assembled document — request it explicitly via `--condition=`. A second opt-in condition, `necromancer-mcp-graph`, adds the `get_artifact`, `get_relationships`, and `get_impact` graph tools to those four, so its difference from `necromancer-mcp` measures what following [Relationships](#relationships) adds; its tools return exactly what the MCP graph tools return. An optional cross-model AI judge scores quality; automated fact-checks always run. Each condition also reports average latency (with standard deviation) for the generation call and, separately, the judge call — shown as `Latency`/`Judge Latency` columns in the terminal and markdown reports, and as raw per-task fields in `--format=json` and the automatic dump; the judge column is omitted entirely when no result carries judge data (e.g. `--no-judge`). When both `necromancer` and `necromancer-mcp` are present in a run, an additional "Necromancer (MCP) vs Necromancer (static)" comparison line shows whether live tool-querying discovers the same facts as effectively as reading the generated document. When both MCP conditions run, a "Necromancer (MCP graph) vs Necromancer (MCP)" line follows.

Q&A tasks (which measure context *coverage*) run under every condition except the static `necromancer` one — Necromancer would trivially score 100% there since the answers are in the context file it generated; the MCP conditions don't have this problem, since the model must still choose the right tool and interpret its output. Two bundled Q&A tasks ask which listener handles each event and what each class dispatches, and `--generate-suite` adds one asking what is directly connected to the model with the most Relationships. Code generation and mini tasks run across all active conditions and measure actual effectiveness.

```bash
php artisan necromancer:benchmark --no-judge              # automated checks only (single provider)
php artisan necromancer:benchmark --format=markdown --output=benchmark.md
php artisan necromancer:benchmark --generate-suite        # generate a suite grounded to your app's manifest
php artisan necromancer:benchmark --condition=necromancer,necromancer-mcp   # static context vs. live tool-querying (opt-in)
php artisan necromancer:benchmark --condition=necromancer-mcp,necromancer-mcp-graph   # flat queries vs. graph tools (opt-in)
```

> See **[BENCHMARK.md](BENCHMARK.md)** for full setup instructions, config reference, and bias mitigations.

---

### Step 3i — Export an OKF Knowledge Bundle

Project the manifest into a portable, deterministic [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) (OKF) bundle — one Markdown file per artifact, with authoritative YAML front matter and a concise prose mirror:

```bash
php artisan necromancer:okf
```

Writes to `okf/` at the project root by default: `okf/bundle.json` (a small index with the bundle version, a `content_hash` copied from the source manifest, and the artifact count), a generated `okf/README.md` explaining the bundle's structure and how to regenerate it, plus one file per artifact under `okf/artifacts/`, named from a readable slug and a short hash of the artifact's canonical ID (e.g. `app-jobs-sendinvoice-20237e38.md`) — the filename is for browsability only, never authoritative; the `necromancer.id` field inside each file's front matter is.

```markdown
---
title: "SendInvoiceEmail"
type: "artifact"
kind: "jobs"
tags:
  - "billing"
necromancer:
  schema_version: 1
  bundle_version: "0.2"
  id: "jobs:App\\Jobs\\SendInvoiceEmail"
  artifact_type: "jobs"
  generated_at: "2026-08-07T12:00:00+02:00"
  facts:
    queue: "emails"
    tries: 3
  annotations:
    domain: "billing"
    risk: "high"
---

# SendInvoiceEmail

_jobs artifact_

## Architectural Context

domain: billing · risk: high

## Discovered Facts

- **queue**: `emails`
- **tries**: `3`
```

Every field is deterministic: `generated_at` always comes from the manifest's own `meta.generated_at`, never the export clock, so re-exporting an unchanged manifest produces byte-identical files. The bundle never becomes a competing source of truth — it's a read-only projection of the manifest, safe to regenerate at any time and never consulted by Necromancer itself.

By default the command refuses to export a manifest that looks stale (source files changed since the last scan) or whose scan was partial (`--only=` was used, or it's an old unversioned manifest) — both are exit-1 failures with an actionable message, not silent best-effort output:

```bash
php artisan necromancer:okf --allow-stale      # export anyway, e.g. in a throwaway CI check
php artisan necromancer:okf --allow-partial    # export a deliberately narrow bundle
php artisan necromancer:okf --output=dist/okf  # write elsewhere
```

Output replacement is safe to interrupt: the whole bundle is built in a temporary directory first, and the real output directory is only ever replaced once every file has been written successfully — a failed export never leaves a previously-generated bundle damaged.

`bundle.json`'s `content_hash` is the source manifest's own `meta.content_hash` at export time, not a timestamp — so it stays comparable across rescans that changed nothing, and lets other tooling tell a bundle apart from the manifest it was built from without being thrown off by a routine no-op rescan.

#### Relationships, Domain/Flow concepts, and ADRs

When an artifact's already-collected fields name another artifact by class — a route's `controller`, a model's `relationships`/`policy`/`observers`, an event's `listeners`, a listener's `handles`, a policy's or observer's `model`, an action's `operates_on` (the class types its entrypoints accept) — the Artifact Concept's body gains a `## Relationships` section rendering each as a Markdown link to that artifact's own concept file when it's resolvable in the bundle, or as plain text when it isn't (a vendor class, or one Necromancer didn't collect):

```markdown
## Relationships

- **controller**: [App\Http\Controllers\OrderController](/artifacts/order-controller-9f21ab34.md)
```

These lines are drawn from the same [Relationships](#relationships) the Artifact Graph uses, labelled by the fact that declares them. A concept only lists facts the artifact itself records — a model shows `policy` only when it declares one with `#[UsePolicy]`, even though the policy's own `model` also supports that Relationship.

After those lines, a concept lists five more Relationship types, one line per type in this order: `uses_middleware`, `validates_with`, a route's `authorized_by`, `dispatches`, and `tested_by`. These appear on the artifact the Relationship starts from, even when another artifact records the fact: a route lists the form request its controller action accepts, and a model lists the tests whose `subject` covers it. Each line is labelled by its type name. Each target links to its concept by Artifact ID, or stays plain text when it wasn't collected, and may carry a short qualifier: the group a middleware was reached through, the ability a route authorizes, the dispatch modes, or a test matched by namespace (`namespace match`) or by a source reference (`reference`):

```markdown
## Relationships

- **controller**: [App\Http\Controllers\OrderController](/artifacts/order-controller-9f21ab34.md)
- **uses_middleware**: [App\Http\Middleware\EncryptCookies (group)](/artifacts/app-http-middleware-encryptcookies-group-d3c9f7aa.md) (via web), auth
- **validates_with**: [App\Http\Requests\StoreOrderRequest](/artifacts/app-http-requests-storeorderrequest-e2e0761e.md)
- **authorized_by**: [App\Policies\OrderPolicy](/artifacts/app-policies-orderpolicy-520cd950.md) (create)
- **tested_by**: [tests/Feature/OrderTest.php](/artifacts/testsfeatureordertestphp-34e9a532.md), [tests/Unit/ModelsTest.php](/artifacts/testsunitmodelstestphp-aa2fdc7e.md) (namespace match)
```

A concept doesn't list the Relationships that point to it: a form request doesn't list the routes it validates. Domain, flow, and ADR Relationships stay in `## Architectural Context`. Enrichment prompts don't include any of these lines.

Every artifact tagged with the same `domain` or `flow` annotation value is also made navigable through a synthesized **Domain Concept** or **Flow Concept** — one file per distinct value, linking every member artifact:

```markdown
---
title: "billing"
type: "domain"
necromancer:
  schema_version: 1
  bundle_version: "0.2"
  id: "domain:billing"
  concept_type: "domain"
  members:
    - "jobs:App\\Jobs\\SendInvoiceEmail"
    - "routes:POST:billing/cancel"
---

# billing

_domain concept_

## Artifacts

- [App\Jobs\SendInvoiceEmail](/artifacts/app-jobs-sendinvoiceemail-20237e38.md)
- [POST billing/cancel](/artifacts/post-billing-cancel-9f21ab34.md)
```

A locally declared `adrs` reference (anything that isn't an absolute URI) is resolved against the application's base path, copied into the bundle as its own **ADR Concept** with provenance, and linked from every artifact that declared it — an absolute URI stays an external Markdown link instead of being copied:

```markdown
adrs: [docs/adr/0004-subscription-cancellation.md](/artifacts/0004-subscription-cancellation-1a2b3c4d.md)
```

A declared local ADR that doesn't exist on disk fails the whole export before anything is written, naming the missing path — the same controlled-failure behavior as a stale or partial manifest.

---

### Step 3j — Generate an AI-Enriched Knowledge Bundle

Layer AI-generated prose onto the deterministic bundle, written to a separate sibling directory — the deterministic bundle from `necromancer:okf` is never modified:

```bash
php artisan necromancer:okf-enrich
```

Writes `okf-enriched/bundle.json` (carrying its own `content_hash`, copied from the source manifest the same way the deterministic bundle's is) and a generated `okf-enriched/README.md` explaining what enrichment can and cannot change, caching, privacy, and configuration — mentioning the deterministic `okf/` sibling it enriches in the same static, unconditional prose the deterministic bundle's own README uses to mention this one.

Every concept in the bundle (artifact, domain, flow, and ADR) is eligible for enrichment, and enrichment can only ever *add* content — it cannot change a concept's facts, annotations, Artifact ID, or links, because the AI's output is never given access to those fields to begin with. The enriched front matter gains a `description` field and a `necromancer.enrichment` block; the body gains an `## AI-Enriched Summary` section:

```markdown
---
title: "SendInvoiceEmail"
type: "artifact"
kind: "jobs"
description: "Sends invoice emails asynchronously outside the request cycle."
necromancer:
  schema_version: 1
  bundle_version: "0.2"
  id: "jobs:App\\Jobs\\SendInvoiceEmail"
  facts:
    queue: "emails"
  annotations:
    domain: "billing"
  enrichment:
    provider: "anthropic"
    model: "claude-sonnet-4-6"
    prompt_version: "1"
    privacy_policy: "excludes-source-framework-config-adr-bodies"
    cache_key: "8f1c2e...:anthropic:claude-sonnet-4-6:default:1"
    cached: false
---

# SendInvoiceEmail

...

## AI-Enriched Summary

This job decouples invoice email delivery from the request cycle, running on the
emails queue so a slow mail provider never blocks the HTTP response.
```

**What the AI never sees.** The prompt built for each concept excludes raw framework metadata (`route_metadata`), source file paths and hashes, application configuration, and — for ADR concepts — the ADR's own copied file content. A domain/flow concept's prompt carries only its value and member ids; an ADR concept's prompt carries only its path and the ids of artifacts that reference it. None of this is a filter applied after the fact — the code that builds each prompt has no parameter through which that content could pass.

**Caching.** Each concept is cached independently, keyed by a hash of its own prompt plus the provider, model, temperature, and prompt version — so changing one artifact's annotations only invalidates that one concept's cached enrichment, not the whole bundle. Re-running the command reuses cached results and reports how many concepts were generated fresh versus reused:

```bash
php artisan necromancer:okf-enrich
# Enriched 12 concept(s) (2 generated, 10 cached) to /path/to/okf-enriched.
```

```bash
php artisan necromancer:okf-enrich --refresh              # bypass the cache, re-enrich every concept
php artisan necromancer:okf-enrich --provider=anthropic --model=claude-sonnet-4-6
php artisan necromancer:okf-enrich --output=dist/okf-enriched
php artisan necromancer:okf-enrich --allow-stale --allow-partial
```

The same stale-manifest and partial-scope refusals as `necromancer:okf` apply, and `--allow-stale`/`--allow-partial` override them the same way. A declared local ADR that doesn't exist on disk fails the command before anything is written, exactly like `necromancer:okf`.

> **Requires** `laravel/ai` installed and an AI provider configured in `config/ai.php`.

---

### Step 3k — Visualize the Artifact Graph

Project the manifest into a deterministic **Artifact Graph** — a node/edge visualization of every collected artifact, viewable as an interactive, force-directed, kind-colored graph in the browser:

```bash
php artisan necromancer:graph
```

Writes two files to `necromancer-graph/` by default: `graph.json` (a standalone, independently useful node/edge list — one node per collected artifact plus their relationships, canonically ordered so an unchanged manifest always produces a byte-identical file) and `graph.html`, a self-contained static viewer with no CDN dependencies. Every collected artifact appears as a node, colored by kind, and every **Relationship** Necromancer derives from the manifest becomes an edge.

#### Relationships

A Relationship is a directed, typed link derived from facts and annotations the manifest already holds — it is never written to `necromancer.json`, so adding or changing one never changes `meta.content_hash`. Each type has one canonical direction, so a fact recorded on both ends (an event's `listeners` and a listener's `handles`) still yields a single Relationship:

| Type | From → to | Derived from | Provenance | Metadata |
|---|---|---|---|---|
| `handled_by` | route → controller | the route's `controller` | runtime | `action` |
| `uses_middleware` | route → middleware | the route's `middleware`; a group name expands to each of its collected members | runtime | `groups` (groups it was reached through), `direct` (also listed directly) |
| `validates_with` | route → form request | form-request-typed parameters of the route's controller action | reflection | — |
| `authorized_by` | route → policy | the route's `#[Authorize]` entries: the policy of each named model, else a gate for the ability | reflection | `ability`, `models` |
| `authorized_by` | model → policy | the model's `policy` and/or the policy's `model` | reflection | `heuristic: true` when only the policy's guessed `model` supports it |
| `relates_to` | model → model | the model's Eloquent `relationships` | runtime | `method`, `kind` (e.g. `belongsTo`) |
| `observed_by` | model → observer | the model's `observers` and/or the observer's `model` | reflection | — |
| `listened_by` | event → listener | the event's `listeners` and/or the listener's `handles` | runtime | — |
| `operates_on` | action → class | class types the action's entrypoints accept | reflection | — |
| `dispatches` | artifact → job, event, or mailable | the artifact's `dispatches` (one Relationship per target, however many methods dispatch it) | source | `methods`, `modes` (deduplicated, `null` excluded) |
| `tested_by` | artifact → test | the test's `subject` — an exact class, or a namespace fanned out to every artifact under it — and each class or route name in the test's `references` | source | `match: exact\|namespace\|reference` (a subject match wins when both link the same pair) |
| `belongs_to_domain` / `belongs_to_flow` | artifact → `domain:<v>` / `flow:<v>` | the artifact's `domain`/`flow` annotation | annotation | — |
| `references_adr` | artifact → `adr:<path>` | each local `adrs` annotation entry (absolute URIs skipped) | annotation | — |

**Provenance** records how the evidence was obtained — `runtime` (the application's runtime state, e.g. the router or event dispatcher), `reflection` (declared code structure), `source` (source text, e.g. test files), or `annotation` (an Artifact Annotation). A Relationship supported by several facts carries each of their provenances.

A Relationship whose end isn't a collected artifact — a vendor controller, a listener handling a framework event, a test subject that matches nothing — keeps the raw class or name for that end and is marked `resolved: false`.

Each `graph.json` edge carries the full Relationship plus the `kind` its type renders as:

```json
{
    "from": "routes:GET:orders",
    "to": "controllers:App\\Http\\Controllers\\OrderController",
    "type": "handled_by",
    "kind": "structural",
    "provenance": ["runtime"],
    "resolved": true,
    "metadata": { "action": "index" }
}
```

`kind` is `grouping` for `belongs_to_domain`/`belongs_to_flow`, `reference` for `references_adr`, `behavioral` for `dispatches`, and `structural` for every other type. Edges are canonically ordered (artifact type, then manifest order), so an unchanged manifest always produces a byte-identical `graph.json`. `graph.html` only draws a line for an edge whose both ends resolve to a visible node, styled distinctly per kind (solid for structural, dashed for grouping, dotted for reference, dash-dot for behavioral) — an unresolved edge still exists in `graph.json`, just isn't drawn.

`graph.html` embeds the graph data directly in the page at write time — just open it in a browser, no local server required. (`graph.json` is still written alongside it as an independent artifact for other tooling to consume; the HTML viewer just doesn't depend on fetching it.)

Each node also carries its Discovered Facts — every field the artifact carries besides `id`/`annotations`/`source`/`route_metadata`, the same exclusion `necromancer:okf`'s Artifact Concepts already apply — so the viewer is fully self-contained for inspection, with nothing further to fetch.

The viewer is interactive:

- **Sidebar** — one row per artifact kind present in the graph, doubling as both a color legend and a filter: unchecking a kind hides its nodes and every edge touching them. **Select all** / **Select none** buttons above the list toggle every kind at once.
- **Edge key** — a small always-visible card showing the solid/dashed/dotted/dash-dot line style for structural/grouping/reference/behavioral edges, each independently toggleable.
- **Click-to-inspect** — click a node to open a panel with its canonical Artifact ID, kind, Architectural Context (resolved annotations), Relationships (each edge touching it, by type, outgoing as `type → target` and incoming as `source → type`, with unresolved ones marked), and Discovered Facts. Clicking a synthesized domain/flow/ADR node shows its member artifacts (or referencing artifacts, for an ADR) instead. Hiding a selected node's kind via the sidebar closes its panel automatically.
- **Zoom & pan** — scroll to zoom toward the cursor, drag empty canvas to pan, drag a node to reposition it. **Zoom in** / **Zoom out** buttons in the header step the same zoom centered on the current viewport, and a **Reset view** button refits the camera to the currently visible nodes.

Like `necromancer:okf`, the command never rescans the application, refuses a stale or partial-scope manifest by default, and writes atomically — a failed run never damages a previously-generated graph:

```bash
php artisan necromancer:graph --allow-stale      # build anyway, e.g. in a throwaway CI check
php artisan necromancer:graph --allow-partial    # build a deliberately narrow graph
php artisan necromancer:graph --output=dist/graph # write elsewhere
```

`necromancer:graph` is entirely independent of `necromancer:okf` — neither requires the other to have run, and their output directories (`necromancer-graph/` vs. `okf/`) never interfere with each other.

---

### Step 3l — Analyze an artifact's Impact

Ask what is connected to one artifact before you change it:

```bash
php artisan necromancer:impact "App\Models\Order" --depth=2
```

```text
Impact of App\Models\Order (models:App\Models\Order), depth 2

Depth 1
  policies
    App\Policies\OrderPolicy  authorized_by →
  actions
    App\Actions\CancelOrder  ← operates_on

Depth 2
  routes
    GET orders  ← authorized_by  via App\Policies\OrderPolicy
```

The command walks [Relationships](#relationships) in both directions, breadth-first, and reports each node once, at its shortest distance from the start. `authorized_by →` means the start side is the Relationship's `from`; `← operates_on` means it is the `to`. From depth 2 on, `via` names the node that reached it. When two Relationships reach a node at the same distance, the first one in canonical Relationship order wins, so an unchanged manifest always produces identical output.

Domains, Flows, ADRs, middleware, and tests are **Boundary Nodes**: they appear in the result, but the walk never continues past them. Walking through them would return every member of a flow, every route in the `web` group, or every subject a namespace-matched test covers. The start always expands, so `necromancer:impact middleware:alias:auth` does list every route using it. A Relationship end Necromancer didn't collect, such as a vendor controller, is listed as `(unresolved)` and never expanded. Unresolved ends with the same raw class or name are one node, reached through whichever Relationship gets there first.

That's why a route reaches a model at depth 2 and not 1: no Relationship links them directly, only `route → authorized_by → policy ← authorized_by ← model`.

A Domain, Flow, or ADR can be the start too. `necromancer:impact flow:checkout` lists every artifact in the `checkout` flow (`← belongs_to_flow`), and from depth 2 what those artifacts connect to.

| Option | Description |
|---|---|
| `artifact` | An exact Artifact ID (`models:App\Models\Order`), a fully-qualified class name, or a `domain:<v>`, `flow:<v>`, or `adr:<path>` ID. A class matching several artifacts (e.g. a middleware registered as an alias and in a group) fails and lists the candidate IDs. A Domain, Flow, or ADR ID works only when at least one artifact declares it, so `flow:nope` fails like an unknown class. |
| `--depth=N` | How many Relationships away to walk. Defaults to 1, must be at least 1. |
| `--type=TYPES` | Only display these node types: comma-separated artifact types plus `domain`, `flow`, `adr`. The walk itself is unchanged, so `--type=tests --depth=2` still finds tests reached through other nodes. Unresolved nodes have no type, so any `--type` filter hides them. When the filter hides every reachable node, the command says how many were reachable instead of "No relationships found". |
| `--json` | Output `{"start", "depth", "nodes": [{"id", "type", "distance", "resolved", "via": {"from", "relationship", "direction"}}]}`, nodes sorted by distance then canonical order, with `--type` applied. An unresolved node has `type: null`. A Relationship with no metadata serializes `metadata` as `{}`, as in `graph.json`. |
| `--allow-stale` / `--allow-partial` | Analyze a stale or partial-scope manifest, which is refused by default exactly as `necromancer:graph` and `necromancer:okf` refuse it. |

---

### Step 3m — Find the tests affected by a change

List the tests to run for a changed artifact:

```bash
php artisan necromancer:affected-tests "App\Models\Order"
```

```text
Affected tests for models:App\Models\Order, depth 2

Directly affected
  tests/Unit/OrderTest.php  ← via App\Models\Order

Indirectly affected
  tests/Unit/OrderPolicyTest.php  ← via App\Policies\OrderPolicy
  tests/Unit/PoliciesTest.php  ← via App\Policies\OrderPolicy  (namespace match)
```

An **Affected Test** is a test reached by the [Impact](#step-3l--analyze-an-artifacts-impact) of the change, through a `tested_by` Relationship. It's **directly affected** when it tests the changed artifact itself (distance 1), and **indirectly affected** when it tests something further out (distance 2 or more). `← via` names the node the test was reached from. A test whose `subject` is a namespace rather than a class (e.g. `uses(App\Policies::class)`) is included and marked `(namespace match)`. A test linked only because its source references the artifact's class or route name (see the `references` fact under [Step 1](#step-1--scan)) is marked `(reference)`, so a feature test calling `route('orders.store')` is directly affected by that route. A test reached from several changed artifacts is listed once, at its smallest distance.

The walk is the one `necromancer:impact` does, so Domains, Flows, ADRs, middleware, and tests are never walked through: two artifacts sharing a flow don't pull in each other's tests.

Instead of one artifact, pipe in the files a branch changed:

```bash
git diff --name-only main | php artisan necromancer:affected-tests --stdin --allow-stale
```

Each path (relative, `./`-prefixed, or absolute under the project) is matched exactly against every artifact's `source.file`. One file can match several artifacts: a controller file is also the source of its routes. A changed test file is directly affected on its own, shown as `(changed)`. Paths no artifact comes from, such as `routes/web.php`, migrations, config, or views, are listed under **Unmapped** and never fail the command.

> **Note:** a git-diff workflow almost always runs against a manifest older than the changed files, which the command refuses as stale. Either run `php artisan necromancer:scan` first, or pass `--allow-stale` and accept that artifacts added by the change aren't known yet.

To run the result directly, `--paths` prints only the test file paths to stdout, directly affected first. Unmapped paths go to stderr:

```bash
git diff --name-only main | php artisan necromancer:affected-tests --stdin --paths --allow-stale | xargs vendor/bin/pest
```

| Option | Description |
|---|---|
| `artifact` | An exact Artifact ID, a fully-qualified class name, or a `domain:<v>`, `flow:<v>`, or `adr:<path>` ID, resolved as `necromancer:impact` resolves it. A Domain, Flow, or ADR yields the tests of its members, as indirectly affected. Pass this or `--stdin`, not both. |
| `--stdin` | Read changed file paths from stdin, one per line. Blank lines and duplicates are skipped. |
| `--depth=N` | How many Relationships away to walk. Defaults to 2, must be at least 1. `--depth=1` returns only directly affected tests. |
| `--json` | Output `{"directly_affected": [...], "indirectly_affected": [...], "unmapped": [...]}`. Each test is `{"file", "id", "distance", "match", "start", "via"}`: `start` is the changed artifact it was reached from, and `via` is the `{from, relationship, direction}` object `necromancer:impact --json` gives each node. A changed test has distance 0 and `match`/`via` set to `null`. |
| `--paths` | Print only the deduplicated test file paths. Can't be combined with `--json`. |
| `--allow-stale` / `--allow-partial` | Analyze a stale or partial-scope manifest, refused by default as in `necromancer:impact`. `--stdin` doesn't imply `--allow-stale`. |

The command exits 0 whenever it can answer, including when no test is affected, and 1 on a missing, stale, or partial manifest, an unknown or ambiguous artifact, an invalid `--depth`, or conflicting inputs.

---

## Commands Reference

| Command | Purpose | Key options |
|---|---|---|
| `necromancer:scan` | Build the application manifest | `--output=PATH`, `--diff`, `--fail-on-drift` |
| `necromancer:map` | Display the manifest in the terminal | `--type=TYPE` |
| `necromancer:audit` | Run the AI-readability audit (violation list) | `--format=text\|json\|markdown`, `--output=PATH`, `--fail-on=SEVERITY` |
| `necromancer:doctor` | Show the AI readability score (percentage dashboard) | `--json`, `--min-score=N`, `--only=KEYS` |
| `necromancer:generate` | Generate the Markdown context file | `--only=TYPE,TYPE`, `--except=TYPE,TYPE` (20 types: routes, controllers, models, actions, jobs, events, listeners, commands, form_requests, policies, enums, tests, observers, scheduled_tasks, middleware, livewire_components, gates, mailables, validation_rules, service_providers), `--paths=PATH,PATH`, `--output=PATH`, `--force` |
| `necromancer:ask` | Ask a question about your codebase via AI | `--provider=`, `--model=` |
| `necromancer:inspect-payload` | Show the AI payload size and content for `necromancer:ask` | `--privacy` |
| `necromancer:prompt` | Generate a source-grounded prompt for any AI tool | `--top=N`, `--no-ai`, `--output=PATH` |
| `necromancer:infer` | Generate ADRs via AI | `--locale=`, `--temperature=`, `--fresh`, `--refresh` |
| `necromancer:diff` | Compare manifests across branches | `--base-manifest=PATH`, `--review`, `--format=markdown`, `--output=PATH` |
| `necromancer:benchmark` | Benchmark AI context effectiveness (accuracy, hallucination rate, latency, token cost) | `--condition=`, `--type=`, `--no-judge`, `--model=`, `--judge=`, `--format=`, `--output=PATH` |
| `necromancer:okf` | Export a deterministic OKF Knowledge Bundle (one Artifact Concept per artifact) | `--output=PATH`, `--allow-stale`, `--allow-partial` |
| `necromancer:okf-enrich` | Generate an AI-enriched sibling OKF bundle (privacy-bounded prose only) | `--output=PATH`, `--allow-stale`, `--allow-partial`, `--provider=`, `--model=`, `--temperature=`, `--refresh` |
| `necromancer:graph` | Build a deterministic Artifact Graph (artifacts and their Relationships) as `graph.json`/`graph.html` | `--output=PATH`, `--allow-stale`, `--allow-partial` |
| `necromancer:impact` | List the artifacts, Domains, Flows, and ADRs connected to an artifact, Domain, Flow, or ADR through its Relationships | `--depth=N`, `--type=TYPES`, `--json`, `--allow-stale`, `--allow-partial` |
| `necromancer:affected-tests` | List the tests affected by a changed artifact, Domain, Flow, or ADR, or by changed files read from stdin | `--stdin`, `--depth=N`, `--json`, `--paths`, `--allow-stale`, `--allow-partial` |

## Configuration

After publishing the config, edit `config/necromancer.php`:

```php
return [

    // Artifact exclusions — supports wildcard patterns for routes and glob patterns for tests
    'exclude' => [
        'routes'     => ['horizon.*', 'telescope.*', 'debugbar.*'],  // matched against the route NAME
        'route_uris' => ['up', 'livewire-*', '_inertia/devtools*'],   // matched against the route URI (works for unnamed routes such as Laravel's /up health check)
        'models'     => [],
        'tests'      => [],   // glob patterns matched against relative file paths, e.g. 'tests/Fixtures/*'
    ],

    // Test discovery roots — override the default tests/Unit and tests/Feature scan paths
    'tests' => [
        'roots' => [
            // ['path' => base_path('tests/Unit'), 'type' => 'unit'],
            // ['path' => base_path('tests/Feature'), 'type' => 'feature'],
        ],
    ],

    // Output paths (defaults shown)
    'output' => [
        'manifest' => base_path('necromancer.json'),
        'context'  => base_path('NECROMANCER.md'),
        'graph'    => base_path('necromancer-graph'),
    ],

    // OKF Knowledge Bundle output directory (necromancer:okf)
    'okf' => [
        'output' => base_path('okf'),

        // Whether necromancer:generate announces a Knowledge Bundle's
        // presence when one exists at the paths below.
        'announce_in_context' => true,

        // AI enrichment (necromancer:okf-enrich)
        'enrichment' => [
            'output' => base_path('okf-enriched'),
            'cache' => storage_path('app/necromancer/okf-enrichment-cache'),
            'provider' => null,
            'model' => null,
            'prompt_version' => '1',
            'privacy_policy' => 'excludes-source-framework-config-adr-bodies',
        ],
    ],

    // Laravel Boost integration
    'boost' => [
        'context_path' => base_path('.ai/guidelines/necromancer.md'),
    ],

    // Route metadata (Laravel 13.17+ Route::metadata()) — the namespace key Necromancer reads
    'route_metadata' => [
        'namespace' => 'necromancer',
    ],

    // Exact-ID annotation mappings for non-reflectable artifacts (closures, test
    // files, gates, scheduled tasks) and registration-specific overrides for
    // reflectable ones. Keys MUST be exact canonical Artifact IDs — no wildcards.
    'annotations' => [
        // 'jobs:App\\Jobs\\SendInvoice' => [
        //     'domain' => 'billing',
        //     'capability' => 'invoice.send',
        //     'risk' => 'high',
        // ],
    ],

];
```

## Privacy & Exclusions

Necromancer is designed to be safe to version by default:

- It never reads `.env` or raw configuration values.
- It never collects or stores application secrets.
- Routes registered by Horizon, Telescope, and Debugbar are excluded from scans automatically.
- Laravel's default `/up` health-check endpoint is excluded automatically via `exclude.route_uris`, so it never shows up as an unnamed-route audit finding.
- Livewire's unnamed asset/dev routes (`livewire-*`, e.g. `livewire.min.js.map`) and Inertia's local-only DevTools routes (`_inertia/devtools*`) are excluded the same way — both packages register these routes without a name, so only URI-based exclusion can reach them.

To exclude additional routes or models, add patterns to the `exclude` key in `config/necromancer.php`. The `exclude.routes` patterns match against the route **name** (and therefore never match unnamed routes); use `exclude.route_uris` to exclude routes by **URI** — including unnamed ones such as health checks. Both use `Str::is()` wildcard matching, and route URIs are matched without a leading slash (e.g. `up`, `orders/*`). Exclusions apply to every downstream command — map, audit, doctor, and generate — so excluded artifacts never appear in results.

## Laravel Boost Integration

When [Laravel Boost](https://github.com/laravel/boost) is installed, `necromancer:generate` automatically writes the context file to `.ai/guidelines/necromancer.md` (configurable via `boost.context_path`) instead of `NECROMANCER.md`. Boost remains responsible for composing the final agent context; Necromancer only contributes its section.

An explicit `--output=PATH` flag always takes precedence over the Boost path.

## MCP Tools

When [Laravel MCP](https://github.com/laravel/mcp) is installed, Necromancer automatically exposes the manifest as read-only MCP tools via a `laravel-necromancer` server handle:

| Tool | Description |
|---|---|
| `query_routes` | List routes, optionally filtered by method or name/URI pattern |
| `query_models` | List Eloquent models, optionally filtered by class name |
| `query_artifacts` | List artifacts of any current type, optionally filtered by JSON substring |
| `search_artifacts` | Full-text search across all artifact types |
| `get_artifact` | Return one artifact's full manifest payload, by Artifact ID or fully-qualified class name |
| `get_relationships` | List every [Relationship](#relationships) an artifact, Domain, Flow, or ADR takes part in, outgoing and incoming |
| `get_impact` | List everything reachable from an artifact, Domain, Flow, or ADR, like [`necromancer:impact --json`](#step-3l--analyze-an-artifacts-impact) |
| `get_affected_tests` | List the tests affected by a changed artifact or by changed file paths, like [`necromancer:affected-tests --json`](#step-3m--find-the-tests-affected-by-a-change) |

Use `query_artifacts` when you already know the artifact type (`routes`, `controllers`, `models`, `form_requests`, `actions`, `jobs`, `events`, `listeners`, `commands`, `observers`, `policies`, `enums`, `tests`, `scheduled_tasks`, `middleware`, `livewire_components`, `gates`, `mailables`, `validation_rules`, or `service_providers`). Use `search_artifacts` when you need to search across types.

#### Graph tools

`get_artifact`, `get_relationships`, `get_impact`, and `get_affected_tests` follow [Relationships](#relationships), so an agent can ask for a model's policy, observers, or flow in one call instead of fetching several artifacts and working out the links itself. They're deterministic and involve no LLM.

- **`get_artifact(artifact)`** returns `{"artifact": <payload>, "warnings": []}`. A `domain:`/`flow:`/`adr:` ID isn't a collected artifact, so it returns a `not_an_artifact` error pointing to `get_relationships`.
- **`get_relationships(artifact)`** returns `{"artifact": <id>, "relationships": [...], "warnings": []}`, listing every Relationship the start takes part in, in canonical order. Each entry is the Relationship as it appears in `graph.json` plus `"direction": "out"` (the start is `from`) or `"in"`. Nothing is deduplicated: a model whose `author` and `assignee` both relate to `User` yields two entries. `get_relationships("flow:checkout")` lists the flow's members, all incoming.
- **`get_impact(artifact, depth?, types?)`** returns exactly the `necromancer:impact --json` shape plus `warnings`. `depth` defaults to 1 and is clamped to 1–3, with a warning when it's clamped. `types` filters the returned nodes, as a comma-separated string or an array, with the same values as `--type`.
- **`get_affected_tests(artifact? | paths?, depth?)`** returns `{"directly_affected", "indirectly_affected", "unmapped", "depth", "warnings"}`. The three sections are exactly what `necromancer:affected-tests --json` gives for the same input, and `depth` is the depth actually used. Pass exactly one of `artifact` or `paths` (a list of changed files, relative, `./`-prefixed, or absolute under the project); both or neither returns an `invalid_input` error. An empty `paths` list is valid and returns empty sections, and `unmapped` is always empty for `artifact`. `depth` defaults to 2, as in the command, and is clamped to 1–3 with a warning. The server's instructions tell agents to call it with the paths they changed after editing files.

`artifact` is an exact Artifact ID or a fully-qualified class name. `get_relationships`, `get_impact`, and `get_affected_tests` also accept a `domain:<v>`, `flow:<v>`, or `adr:<path>` ID that at least one artifact declares.

Unlike `necromancer:impact`, these tools don't refuse a stale or partial-scope manifest: an agent makes the manifest stale with its first edit, and has no `--allow-stale` to pass. They answer anyway and report it in `warnings`: one entry when source files have changed since the scan, and one when the scan didn't cover every artifact type (naming the types it did cover). On a stale manifest, `get_affected_tests` adds one more when some paths are unmapped: they may be files created or moved since the scan, which `php artisan necromancer:scan` would map.

Other failures return an MCP error whose text is `{"error": <code>, "message": <string>}`:

| Code | When |
|---|---|
| `ambiguous` | A class matches several artifacts; the body also lists their IDs under `candidates` |
| `not_found` | Nothing matches the input |
| `not_an_artifact` | `get_artifact` was given a Domain, Flow, or ADR ID |
| `invalid_type` | `get_impact`'s `types` names an unknown type |
| `invalid_input` | `get_affected_tests` was given both `artifact` and `paths`, or neither |
| `manifest_not_found` | The manifest is missing or predates schema v1; run `php artisan necromancer:scan` |

The four query tools return an empty list when the manifest is missing, as before.

When `laravel/mcp` is present, Necromancer also writes its entry into `.mcp.json` automatically on the first `php artisan` run after installation — no manual configuration needed. If `.mcp.json` already exists, the entry is merged without touching other servers.

AI agents connected via Claude Code, Cursor, or any MCP client can then call these tools directly rather than reading `necromancer.json` by hand.

> **Requires** `laravel/mcp` installed. Run `php artisan necromancer:scan` to ensure the manifest is current before connecting an agent.

## CI Integration

Add these steps to your CI pipeline to enforce manifest freshness and AI-readability quality:

```yaml
- name: Check manifest is up to date
  run: php artisan necromancer:scan --diff --fail-on-drift

- name: Fail on AI-readability errors
  run: php artisan necromancer:audit --fail-on=error

- name: Enforce minimum AI readability score
  run: php artisan necromancer:doctor --min-score=80
```

## Upgrading to 2.0

Version 2.0 removes the 1.x-only compatibility surfaces that existed to ease the transition to universal Artifact Annotations (1.5.0) and Knowledge Bundles (1.6.0/1.7.0). Nothing about how you *declare* annotations changes — `#[Necromancer]`, `withNecromancer()`, and exact-ID config mappings all work exactly as before. What changes is what 2.0 stops reading, emitting, and accepting.

**1. Manifests older than schema v1 are now rejected, not upgraded.**

Every command that reads `necromancer.json` (`map`, `audit`, `doctor`, `generate`, `ask`, `prompt`, `infer`, `diff`, `okf`, the MCP tools, and more) previously detected a pre-1.5 manifest and silently promoted it in memory. In 2.0 that promotion step is gone: a manifest missing `meta.manifest_schema_version: 1` is treated exactly like a missing manifest, and every command shows the same "Necromancer manifest not found. Run necromancer:scan first." error.

> **Action:** run `php artisan necromancer:scan` once after upgrading to regenerate `necromancer.json`. If you commit the manifest to git, commit the regenerated file too. CI pipelines that only run `necromancer:audit`/`necromancer:doctor`/etc. against a checked-in manifest will fail until it's rescanned with 2.0 installed.

**2. `route_metadata.necromancer` no longer appears in the manifest.**

Routes still carry `route_metadata.raw` — the untouched output of Laravel's native `Route::getMetadata()` — but the `route_metadata.necromancer` projection that mirrored resolved annotations back onto routes specifically has been removed. Resolved annotations for routes (and every other artifact family) live in the universal `annotations` key, which has been present on every artifact since 1.5.0.

> **Action:** if any of your own tooling reads `route_metadata.necromancer.*` from `necromancer.json` directly, switch it to read the artifact's `annotations.*` key instead. Every built-in consumer (`doctor`, `audit`, `generate`, `diff`, `ask`, MCP tools) already reads `annotations` and requires no changes.

**3. `necromancer:doctor --only=route-metadata-coverage` no longer matches anything.**

The dimension's canonical key has been `artifact-annotation-coverage` since 1.5.0; 2.0 removes the `route-metadata-coverage` alias that `--only` accepted for backward compatibility.

> **Action:** update any CI script or shell alias using `--only=route-metadata-coverage` to `--only=artifact-annotation-coverage`.

**4. Two scan diagnostic codes were renamed.**

`AN_LEGACY_VALUE` and `AN_LEGACY_RISK` — printed by `necromancer:scan` when a native `Route::metadata()` declaration carries a value that can't fit Annotation Schema v1 (e.g. `risk: 'yolo'`) — are renamed to `AN_SCHEMA_INCOMPATIBLE_VALUE` and `AN_SCHEMA_INCOMPATIBLE_RISK`. The check itself is unchanged; only the code name changed, since it was never actually about manifest schema age.

> **Action:** update any log parsing or CI assertions that grep scan output for `AN_LEGACY_VALUE`/`AN_LEGACY_RISK`.

**5. The singular `adr` parameter was removed from `withNecromancer()` and `RouteMetadataFactory::forMetadata()`.**

Both accept a plural `adrs` array parameter (added in 1.5.0 alongside `adr`) — that's now the only way to declare ADR references through the macro or factory.

```diff
 Route::post('/billing/cancel', [SubscriptionController::class, 'cancel'])
     ->withNecromancer(
         domain: 'billing',
         risk: 'high',
-        adr: 'docs/adr/004-subscription-cancellation.md',
+        adrs: ['docs/adr/004-subscription-cancellation.md'],
     );
```

> **Action:** search your route files for `->withNecromancer(` calls passing `adr:` and switch them to `adrs: [...]`. The raw-array form (`->metadata(['necromancer' => ['adr' => ...]])`) is unaffected — Necromancer still reads a singular `adr` key there and merges it into `adrs`, since that's native Laravel data Necromancer doesn't control, not a Necromancer-specific compatibility shim.

## Contributing

Bug reports and pull requests are welcome on the [GitHub repository](https://github.com/robertogallea/laravel-necromancer).

The package's architectural decisions — and why they were made — are recorded as ADRs in [`docs/adr/`](docs/adr). Read the ones covering the area you're changing before "fixing" something that looks odd; it may be deliberate.

## License

MIT
