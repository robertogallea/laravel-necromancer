<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use LaravelNecromancer\Diff\DiffReviewAgent;
use LaravelNecromancer\Diff\DiffReviewResult;
use LaravelNecromancer\Diff\ManifestDiff;
use LaravelNecromancer\Integrations\AiDetector;

/**
 * @param  array<string, mixed>  $artifacts
 * @return array<string, mixed>
 */
function makeDiffManifest(array $artifacts = []): array
{
    return [
        'meta' => ['manifest_schema_version' => 1,
            'generated_at' => '2026-01-01T00:00:00Z',
            'content_hash' => hash('sha256', json_encode($artifacts, JSON_THROW_ON_ERROR)),
            'necromancer_version' => '1.0.0',
            'laravel_version' => '13.0',
            'php_version' => '8.4',
            'app_name' => 'TestApp',
            'app_url' => 'http://localhost',
            'app_env' => 'testing',
        ],
        'artifacts' => $artifacts,
    ];
}

/**
 * Write a manifest array to a temp file and return the path.
 *
 * @param  array<string, mixed>  $manifest
 */
function writeTempManifest(array $manifest): string
{
    $path = tempnam(sys_get_temp_dir(), 'necromancer_');
    file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

    return $path;
}

beforeEach(function () {
    $this->instance(AiDetector::class, new AiDetector(ServiceProvider::class));

    File::delete(base_path('necromancer.json'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
});

test('the diff command is registered in artisan', function () {
    $this->artisan('list')
        ->expectsOutputToContain('necromancer:diff')
        ->assertSuccessful();
});

test('shows diff when using --base-manifest with different manifests', function () {
    $baseManifest = makeDiffManifest(['routes' => []]);
    $headManifest = makeDiffManifest([
        'routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index']],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('1 added')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('shows no drift when manifests are identical', function () {
    $manifest = makeDiffManifest(['routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index']]]);

    $baseFile = writeTempManifest($manifest);
    File::put(base_path('necromancer.json'), json_encode($manifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('No architectural drift detected')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('fails with a clear message when --base-manifest predates schema v1', function () {
    $baseFile = writeTempManifest([
        'meta' => ['generated_at' => '2026-01-01T00:00:00Z'],
        'artifacts' => ['routes' => [['method' => 'POST', 'uri' => 'billing/cancel']]],
    ]);
    File::put(base_path('necromancer.json'), json_encode(makeDiffManifest(['routes' => []]), JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('predates schema v1')
            ->assertFailed();
    } finally {
        File::delete($baseFile);
    }
});

test('fails when head manifest is missing', function () {
    $baseFile = writeTempManifest(makeDiffManifest());

    config(['necromancer.output.manifest' => base_path('necromancer-missing.json')]);

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('Manifest not found')
            ->assertExitCode(1);
    } finally {
        File::delete($baseFile);
    }
});

test('fails when --base-manifest file is missing', function () {
    File::put(base_path('necromancer.json'), json_encode(makeDiffManifest(), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:diff', ['--base-manifest' => '/non/existent/manifest.json'])
        ->expectsOutputToContain('Base manifest file not found')
        ->assertExitCode(1);
});

test('warns when --review is used without laravel/ai', function () {
    $this->instance(AiDetector::class, new AiDetector('NonExistent\\Ai\\AiServiceProvider'));

    $manifest = makeDiffManifest(['routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index']]]);
    $baseFile = writeTempManifest(makeDiffManifest());
    File::put(base_path('necromancer.json'), json_encode($manifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile, '--review' => true])
            ->expectsOutputToContain('laravel/ai is not installed')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('writes to file when --output is provided', function () {
    $outputPath = storage_path('framework/testing/necromancer-diff-output.txt');
    File::delete($outputPath);

    $baseManifest = makeDiffManifest();
    $headManifest = makeDiffManifest([
        'routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index']],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile, '--output' => $outputPath])
            ->assertExitCode(0);

        expect(File::exists($outputPath))->toBeTrue()
            ->and(File::get($outputPath))->toContain('1 added');
    } finally {
        File::delete($baseFile);
        File::delete($outputPath);
    }
});

test('outputs markdown when --format=markdown', function () {
    $manifest = makeDiffManifest();
    $baseFile = writeTempManifest($manifest);
    File::put(base_path('necromancer.json'), json_encode($manifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile, '--format' => 'markdown'])
            ->expectsOutputToContain('## Necromancer Branch Diff')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('shows removed artifact in diff output', function () {
    $baseManifest = makeDiffManifest([
        'routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index']],
    ]);
    $headManifest = makeDiffManifest(['routes' => []]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('1 removed')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('shows changed artifact in diff output', function () {
    $baseManifest = makeDiffManifest([
        'routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index', 'middleware' => ['web']]],
    ]);
    $headManifest = makeDiffManifest([
        'routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index', 'middleware' => ['web', 'auth']]],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('1 changed')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('flags a newly added high-risk route in text output', function () {
    $baseManifest = makeDiffManifest(['routes' => []]);
    $headManifest = makeDiffManifest([
        'routes' => [[
            'method' => 'POST', 'uri' => '/billing/cancel', 'name' => 'billing.cancel', 'middleware' => [],
            'annotations' => ['domain' => 'billing', 'risk' => 'high'],
        ]],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('FLAGGED ARTIFACTS')
            ->expectsOutputToContain('POST /billing/cancel')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('flags a newly added external-service route in markdown output', function () {
    $baseManifest = makeDiffManifest(['routes' => []]);
    $headManifest = makeDiffManifest([
        'routes' => [[
            'method' => 'POST', 'uri' => '/stripe/webhook', 'name' => 'stripe.webhook', 'middleware' => [],
            'annotations' => ['domain' => 'billing', 'external_services' => ['stripe']],
        ]],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile, '--format' => 'markdown'])
            ->expectsOutputToContain('### Flagged Artifacts')
            ->expectsOutputToContain('stripe')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('flagged route text output shows domain flow and capability when available', function () {
    $baseManifest = makeDiffManifest(['routes' => []]);
    $headManifest = makeDiffManifest([
        'routes' => [[
            'method' => 'POST', 'uri' => '/billing/cancel', 'name' => 'billing.cancel', 'middleware' => [],
            'annotations' => [
                'domain' => 'billing',
                'flow' => 'subscription-cancellation',
                'capability' => 'subscription.cancel',
                'risk' => 'high',
            ],
        ]],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('domain: billing · flow: subscription-cancellation · capability: subscription.cancel · risk: high')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('flagged route markdown output shows domain flow and capability when available', function () {
    $baseManifest = makeDiffManifest(['routes' => []]);
    $headManifest = makeDiffManifest([
        'routes' => [[
            'method' => 'POST', 'uri' => '/stripe/webhook', 'name' => 'stripe.webhook', 'middleware' => [],
            'annotations' => [
                'domain' => 'billing',
                'flow' => 'stripe-webhook-processing',
                'capability' => 'billing.webhook.receive',
                'external_services' => ['stripe'],
            ],
        ]],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile, '--format' => 'markdown'])
            ->expectsOutputToContain('domain: billing · flow: stripe-webhook-processing · capability: billing.webhook.receive · external services: stripe')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('flagged route output omits domain flow and capability when not declared', function () {
    $baseManifest = makeDiffManifest(['routes' => []]);
    $headManifest = makeDiffManifest([
        'routes' => [[
            'method' => 'POST', 'uri' => '/billing/cancel', 'name' => 'billing.cancel', 'middleware' => [],
            'annotations' => ['risk' => 'high'],
        ]],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('risk: high')
            ->doesntExpectOutputToContain('domain:')
            ->doesntExpectOutputToContain('flow:')
            ->doesntExpectOutputToContain('capability:')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('does not flag routes without high risk or external services', function () {
    $baseManifest = makeDiffManifest(['routes' => []]);
    $headManifest = makeDiffManifest([
        'routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index', 'middleware' => []]],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->doesntExpectOutputToContain('Flagged Artifacts')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('flags a newly added high-risk job in text output, not just routes', function () {
    $baseManifest = makeDiffManifest(['jobs' => []]);
    $headManifest = makeDiffManifest([
        'jobs' => [[
            'class' => 'App\\Jobs\\SyncStripeInvoices',
            'annotations' => ['domain' => 'billing', 'risk' => 'critical', 'external_services' => ['stripe']],
        ]],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('FLAGGED ARTIFACTS')
            ->expectsOutputToContain('jobs  SyncStripeInvoices')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('flagged artifact output includes the canonical artifact id, in text and markdown', function () {
    $baseManifest = makeDiffManifest(['jobs' => []]);
    $headManifest = makeDiffManifest([
        'jobs' => [[
            'class' => 'App\\Jobs\\SyncStripeInvoices',
            'annotations' => ['risk' => 'high'],
        ]],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('jobs:App\\Jobs\\SyncStripeInvoices')
            ->assertExitCode(0);

        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile, '--format' => 'markdown'])
            ->expectsOutputToContain('jobs:App\\Jobs\\SyncStripeInvoices')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('flags a newly added high-risk gate with a non-empty label, even though gates have no class field', function () {
    $baseManifest = makeDiffManifest(['gates' => []]);
    $headManifest = makeDiffManifest([
        'gates' => [[
            'ability' => 'manage-billing', 'kind' => 'closure', 'parameters' => [],
            'annotations' => ['risk' => 'high'],
        ]],
    ]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('FLAGGED ARTIFACTS')
            ->expectsOutputToContain('gates  manage-billing')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

it('includes AI review section when --review is used with AI available', function () {
    $this->instance(AiDetector::class, new AiDetector(ServiceProvider::class));

    $this->instance(DiffReviewAgent::class, new class
    {
        public function review(ManifestDiff $diff, string $manifestSummary, string $baseBranch, string $appName): DiffReviewResult
        {
            return new DiffReviewResult(
                summary: 'This PR changes subscription behavior.',
                evidence: ['New listener: ActivateSubscription'],
                risks: ['No webhook test detected'],
                reviewerQuestions: ['Should this be idempotent?'],
                promptTokens: 10,
                completionTokens: 20,
            );
        }
    });

    $baseManifest = makeDiffManifest();
    $headManifest = makeDiffManifest(['routes' => [['method' => 'POST', 'uri' => '/stripe/webhook', 'name' => 'stripe.webhook', 'middleware' => [], 'controller' => null, 'action' => null]]]);
    $baseFile = writeTempManifest($baseManifest);
    file_put_contents(base_path('necromancer.json'), json_encode($headManifest));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile, '--review' => true])
            ->expectsOutputToContain('AI Review')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

test('labels tests artifacts using the file basename', function () {
    $testArtifact = [
        'file' => 'tests/Feature/Auth/AuthenticationTest.php',
        'type' => 'feature',
        'subject' => 'App\\Auth\\Authentication',
        'methods' => ['login screen can be rendered'],
        'source' => ['file' => 'tests/Feature/Auth/AuthenticationTest.php', 'line' => 1, 'line_end' => 41, 'hash' => 'abc123'],
    ];

    $baseManifest = makeDiffManifest(['tests' => []]);
    $headManifest = makeDiffManifest(['tests' => [$testArtifact]]);

    $baseFile = writeTempManifest($baseManifest);
    File::put(base_path('necromancer.json'), json_encode($headManifest, JSON_THROW_ON_ERROR));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile])
            ->expectsOutputToContain('AuthenticationTest.php')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});

it('renders artifact sections in markdown format', function () {
    $baseManifest = makeDiffManifest();
    $headManifest = makeDiffManifest(['routes' => [['method' => 'GET', 'uri' => '/new-route', 'name' => 'new.route', 'middleware' => [], 'controller' => null, 'action' => null]]]);
    $baseFile = writeTempManifest($baseManifest);
    file_put_contents(base_path('necromancer.json'), json_encode($headManifest));

    try {
        $this->artisan('necromancer:diff', ['--base-manifest' => $baseFile, '--format' => 'markdown'])
            ->expectsOutputToContain('### Added')
            ->assertExitCode(0);
    } finally {
        File::delete($baseFile);
    }
});
