<?php

// Generic benchmark task suite — works on any Laravel app.
// All assertions use manifest-grounded fact keys; no hardcoded class or route names.
// required_key: task is skipped if the fact key resolves to null/empty from the manifest.
// must_recall_from: recall rate against a resolved manifest list (grounded accuracy).
// must_contain: generic Laravel framework patterns (case-insensitive).
// must_not_contain: universal hallucination markers (case-insensitive).
return [

    // ─── Q&A tasks (5) ───────────────────────────────────────────────────────

    [
        'id' => 'qa-001',
        'type' => 'qa',
        'prompt' => 'What routes in this application require authentication? List their names and HTTP methods.',
        'required_key' => 'routes.auth_required',
        'conditions' => ['none', 'manual'],
        'assertions' => [
            'must_recall_from' => 'routes.auth_required',
            'fact_keys' => ['routes.auth_required'],
        ],
    ],

    [
        'id' => 'qa-002',
        'type' => 'qa',
        'prompt' => 'Which Eloquent models in this application have observers attached? List the observer class names.',
        'required_key' => 'models.with_observers',
        'conditions' => ['none', 'manual'],
        'assertions' => [
            'must_recall_from' => 'models.with_observers',
            'must_not_contain' => ['no observer', 'does not have', 'observer does not exist'],
            'fact_keys' => ['models.with_observers'],
        ],
    ],

    [
        'id' => 'qa-003',
        'type' => 'qa',
        'prompt' => 'What jobs exist in this application, and what are their queue names and retry settings?',
        'required_key' => 'jobs.named',
        'conditions' => ['none', 'manual'],
        'assertions' => [
            'must_recall_from' => 'jobs.named',
            'must_not_contain' => ['no jobs', 'no queue'],
            'fact_keys' => ['jobs.named'],
        ],
    ],

    [
        'id' => 'qa-004',
        'type' => 'qa',
        'prompt' => 'Which Eloquent models in this application declare casts? List the model names.',
        'required_key' => 'models.with_casts',
        'conditions' => ['none', 'manual'],
        'assertions' => [
            'must_recall_from' => 'models.with_casts',
            'must_not_contain' => ['no casts', 'no models'],
            'fact_keys' => ['models.with_casts'],
        ],
    ],

    [
        'id' => 'qa-005',
        'type' => 'qa',
        'prompt' => 'Which Eloquent models have a corresponding policy registered in this application?',
        'required_key' => 'policies.models',
        'conditions' => ['none', 'manual'],
        'assertions' => [
            'must_recall_from' => 'policies.models',
            'must_not_contain' => ['no policies'],
            'fact_keys' => ['policies.models'],
        ],
    ],

    // ─── Code generation tasks (4) ───────────────────────────────────────────

    [
        'id' => 'codegen-001',
        'type' => 'codegen',
        'prompt' => 'Add a new route to this application following the existing naming convention. Apply the auth middleware and add a matching authorize() call in the controller method.',
        'required_key' => 'routes.named',
        'assertions' => [
            'must_contain' => ['auth', 'authorize'],
            'fact_keys' => ['routes.named'],
        ],
    ],

    [
        'id' => 'codegen-002',
        'type' => 'codegen',
        'prompt' => "Add an array cast to a model in this application. Show the correct \$casts syntax following the casting conventions already used.",
        'required_key' => 'models.with_casts',
        'assertions' => [
            'must_contain' => ["'array'"],
            'must_not_contain' => ['protected $casts = []'],
            'fact_keys' => ['models.with_casts'],
        ],
    ],

    [
        'id' => 'codegen-003',
        'type' => 'codegen',
        'prompt' => "Write a FormRequest class for creating a new resource in this application. Include validation rules appropriate for an existing model's fillable fields.",
        'required_key' => 'routes.auth_required',
        'assertions' => [
            'must_contain' => ['FormRequest', 'rules'],
            'must_not_contain' => ['$request->validate(', 'Validator::make('],
            'fact_keys' => ['routes.named'],
        ],
    ],

    [
        'id' => 'codegen-004',
        'type' => 'codegen',
        'prompt' => 'Register a new queued listener for one of the events in this application. Show the listener class with a handle method and explain how to register it.',
        'required_key' => 'events.named',
        'assertions' => [
            'must_recall_from' => 'events.named',
            'must_contain' => ['ShouldQueue', 'handle'],
            'fact_keys' => ['events.named'],
        ],
    ],

    // ─── Mini end-to-end tasks (3) ────────────────────────────────────────────

    [
        'id' => 'mini-001',
        'type' => 'mini',
        'prompt' => "Add a feature to perform a bulk operation on a resource in this application. Implement: a named route following this application's conventions, a controller method with policy authorization, and a queued job that performs the operation.",
        'required_key' => 'routes.auth_required',
        'assertions' => [
            'must_contain' => ['auth', 'authorize', 'dispatch', 'ShouldQueue'],
            'fact_keys' => ['routes.named', 'policies.models'],
        ],
    ],

    [
        'id' => 'mini-002',
        'type' => 'mini',
        'prompt' => "Add an email notification when one of this application's events fires. Implement a queued listener for an existing event, a Mailable class, and show the registration.",
        'required_key' => 'events.named',
        'assertions' => [
            'must_recall_from' => 'events.named',
            'must_contain' => ['Mailable', 'ShouldQueue', 'handle'],
            'must_not_contain' => ['Mail::raw(', 'sendmail'],
            'fact_keys' => ['events.named'],
        ],
    ],

    [
        'id' => 'mini-003',
        'type' => 'mini',
        'prompt' => 'Expose a JSON API endpoint that lists resources for one of the authenticated routes in this application. The endpoint must be authenticated, return data using an Eloquent API Resource, and follow the naming conventions used.',
        'required_key' => 'routes.auth_required',
        'assertions' => [
            'must_contain' => ['JsonResource', 'auth', 'toArray'],
            'must_not_contain' => ['response()->json('],
            'fact_keys' => ['routes.named'],
        ],
    ],
];
