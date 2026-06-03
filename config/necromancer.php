<?php

declare(strict_types=1);

return [
    'exclude' => [
        'routes' => ['horizon.*', 'telescope.*', 'debugbar.*'],
        'models' => [],
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
        'model'    => null,
        'critic'   => [
            'enabled' => true,
        ],
        'output'   => [
            'adrs' => base_path('docs/adr/necromancer'),
        ],
    ],
];
