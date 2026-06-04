<?php

// Laraboard benchmark task suite
// fact_keys use dot-notation: {type}.{field}.{ShortClassName}
// must_contain / must_not_contain use exact substrings to search in the AI response.
return [

    // ─── Q&A tasks (5) ───────────────────────────────────────────────────────

    [
        'id' => 'qa-001',
        'type' => 'qa',
        'prompt' => 'What routes in this application require authentication? List their names and HTTP methods.',
        'assertions' => [
            'must_contain' => ['auth'],
            'must_not_contain' => ['about', 'up'],
            'fact_keys' => ['routes.named'],
        ],
    ],

    [
        'id' => 'qa-002',
        'type' => 'qa',
        'prompt' => 'What does the Issue model observer do, and which events does it handle?',
        'assertions' => [
            'must_contain' => ['Issue'],
            'must_not_contain' => ['IssueObserver does not exist', 'no observer'],
            'fact_keys' => ['models.observers.Issue'],
        ],
    ],

    [
        'id' => 'qa-003',
        'type' => 'qa',
        'prompt' => 'Which jobs in this application are queued, and what are their retry settings (queue name, tries, timeout)?',
        'assertions' => [
            'must_contain' => [],
            'must_not_contain' => ['no jobs', 'no queue'],
            'fact_keys' => ['jobs.queue.CloseIssuesJob', 'jobs.tries.CloseIssuesJob'],
        ],
    ],

    [
        'id' => 'qa-004',
        'type' => 'qa',
        'prompt' => 'What Eloquent casts are declared on the Project model?',
        'assertions' => [
            'must_contain' => ['Project'],
            'must_not_contain' => ['no casts', 'Project model does not exist'],
            'fact_keys' => ['models.casts.Project'],
        ],
    ],

    [
        'id' => 'qa-005',
        'type' => 'qa',
        'prompt' => 'Which Eloquent models have a corresponding policy registered in this application?',
        'assertions' => [
            'must_contain' => [],
            'must_not_contain' => ['no policies'],
            'fact_keys' => ['routes.named'],
        ],
    ],

    // ─── Code generation tasks (4) ───────────────────────────────────────────

    [
        'id' => 'codegen-001',
        'type' => 'codegen',
        'prompt' => 'Add a route to archive a single issue. Use the correct route name convention for this application, apply the auth middleware, and add a matching policy method.',
        'assertions' => [
            'must_contain' => ['auth', 'archive'],
            'must_not_contain' => ["Route::get('/issues'", "Route::get('/archive'"],
            'fact_keys' => ['routes.named'],
        ],
    ],

    [
        'id' => 'codegen-002',
        'type' => 'codegen',
        'prompt' => 'Add a `settings` cast to the Project model so that the `settings` column is automatically cast to an array.',
        'assertions' => [
            'must_contain' => ["'settings'", "'array'", 'Project'],
            'must_not_contain' => ['Settings::class', 'protected $casts = []'],
            'fact_keys' => ['models.casts.Project'],
        ],
    ],

    [
        'id' => 'codegen-003',
        'type' => 'codegen',
        'prompt' => 'Write a FormRequest class for creating a new milestone. Include validation rules appropriate for the Milestone model in this application.',
        'assertions' => [
            'must_contain' => ['FormRequest', 'rules', 'Milestone'],
            'must_not_contain' => ['$request->validate(', 'Validator::make('],
            'fact_keys' => [],
        ],
    ],

    [
        'id' => 'codegen-004',
        'type' => 'codegen',
        'prompt' => 'Register a queued listener for the IssueAssigned event in this application. Show the listener class, the handle method, and how to register it.',
        'assertions' => [
            'must_contain' => ['IssueAssigned', 'ShouldQueue', 'handle'],
            'must_not_contain' => ['IssueCreated', 'ProjectCreated'],
            'fact_keys' => [],
        ],
    ],

    // ─── Mini end-to-end tasks (3) ────────────────────────────────────────────

    [
        'id' => 'mini-001',
        'type' => 'mini',
        'prompt' => 'Add a feature to close all open issues in a project at once. Implement: a named route following this application\'s conventions, a controller method with proper policy authorization, and a queued job that performs the closing.',
        'assertions' => [
            'must_contain' => ['auth', 'authorize', 'dispatch', 'ShouldQueue'],
            'must_not_contain' => ["Route::get('/close'"],
            'fact_keys' => ['routes.named'],
        ],
    ],

    [
        'id' => 'mini-002',
        'type' => 'mini',
        'prompt' => 'Add email notification when a milestone is completed. Implement a listener that fires on the MilestoneCompleted event, a Mailable class, and configure the listener to be queued.',
        'assertions' => [
            'must_contain' => ['Mailable', 'ShouldQueue', 'handle', 'MilestoneCompleted'],
            'must_not_contain' => ['Mail::raw(', 'sendmail'],
            'fact_keys' => [],
        ],
    ],

    [
        'id' => 'mini-003',
        'type' => 'mini',
        'prompt' => 'Expose a JSON API endpoint that lists open issues for a project. The endpoint must be authenticated, return data using an Eloquent API Resource, and follow the naming conventions used in this application.',
        'assertions' => [
            'must_contain' => ['JsonResource', 'auth', 'toArray'],
            'must_not_contain' => ['response()->json(', "Route::get('/api/issues'"],
            'fact_keys' => ['routes.named'],
        ],
    ],
];
