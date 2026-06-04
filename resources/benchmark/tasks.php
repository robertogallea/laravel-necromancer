<?php

// Stub — full Laraboard task suite added later
return [
    [
        'id' => 'qa-001',
        'type' => 'qa',
        'prompt' => 'What routes require authentication?',
        'assertions' => [
            'must_contain' => [],
            'must_not_contain' => [],
            'fact_keys' => ['routes.named'],
        ],
    ],
];
