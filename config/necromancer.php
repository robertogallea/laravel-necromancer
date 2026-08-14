<?php

declare(strict_types=1);

return [
    'exclude' => [
        'routes' => [
            'horizon.*', 'telescope.*', 'debugbar.*',
            '*livewire*', 'ignition.*', 'health',
            'boost.*', 'storage.*',
            'password.*', 'verification.*', 'login', 'logout', 'register',
        ],
        'route_uris' => ['up'],
        'models' => [],
        'tests' => [],
    ],

    'tests' => [
        'roots' => [],
    ],

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

    'output' => [
        'manifest' => base_path('necromancer.json'),
        'context' => base_path('NECROMANCER.md'),
        'claude' => base_path('CLAUDE.md'),
        'agents' => base_path('AGENTS.md'),
        'graph' => base_path('necromancer-graph'),
    ],

    'okf' => [
        'output' => base_path('okf'),

        // Whether necromancer:generate announces a Knowledge Bundle's
        // presence in its output when one exists at the configured
        // default path(s) below. Set to false to suppress the section
        // entirely, independent of whether a bundle exists.
        'announce_in_context' => true,

        'enrichment' => [
            'output' => base_path('okf-enriched'),
            'cache' => storage_path('app/necromancer/okf-enrichment-cache'),
            'provider' => null,
            'model' => null,
            'prompt_version' => '1',
            'privacy_policy' => 'excludes-source-framework-config-adr-bodies',
        ],
    ],

    'boost' => [
        'context_path' => base_path('.ai/guidelines/necromancer.md'),
        // A directory containing a SKILL.md file — the shape Laravel Boost's
        // SkillComposer discovers. Not a flat file.
        'skill_path' => base_path('.ai/skills/necromancer'),
    ],

    'inference' => [
        'provider' => null,
        'model' => null,
        'critic' => [
            'enabled' => true,
        ],
        'output' => [
            'adrs' => base_path('docs/adr/necromancer'),
        ],
    ],

    'benchmark' => [
        'manual_context_path' => base_path('AGENTS.md'),
        'generation_model' => env('NECROMANCER_BENCH_MODEL', 'claude-sonnet-4-6'),
        'generation_provider' => env('NECROMANCER_BENCH_PROVIDER'),
        'judge_model' => env('NECROMANCER_BENCH_JUDGE', 'gpt-4o'),
        'judge_provider' => env('NECROMANCER_BENCH_JUDGE_PROVIDER'),
        'timeout' => (int) env('NECROMANCER_BENCH_TIMEOUT', 120),
        'dump' => [
            'enabled' => filter_var(env('NECROMANCER_BENCH_DUMP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'path' => env('NECROMANCER_BENCH_DUMP_PATH') ?: storage_path('app/necromancer/benchmarks'),
        ],
        'tasks' => [],
    ],
];
