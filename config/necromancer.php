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

    'output' => [
        'manifest' => base_path('necromancer.json'),
        'context' => base_path('NECROMANCER.md'),
        'claude' => base_path('CLAUDE.md'),
        'agents' => base_path('AGENTS.md'),
    ],

    'boost' => [
        'context_path' => base_path('.ai/guidelines/necromancer.md'),
        'skill_path' => base_path('.ai/skills/necromancer.md'),
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
