<?php

// Laraboard benchmark task suite
// Assertions use manifest-grounded fact keys wherever possible.
// must_recall_from: recall rate against a resolved manifest list (grounded accuracy).
// must_contain:     structural keywords present regardless of context (case-insensitive).
// must_not_contain: hard exclusions for known hallucination markers (case-insensitive).
// required_key:     task is skipped if this fact key resolves to null from the manifest.
return [

    // ─── Q&A tasks (5) ───────────────────────────────────────────────────────

    [
        'id' => 'qa-001',
        'type' => 'qa',
        'prompt' => 'What routes in this application require authentication? List their names and HTTP methods.',
        'required_key' => 'routes.auth_required',
        'assertions' => [
            'must_recall_from' => 'routes.auth_required',
            'must_not_contain' => ['about', ' up '],
            'fact_keys' => ['routes.auth_required'],
        ],
    ],

    [
        'id' => 'qa-002',
        'type' => 'qa',
        'prompt' => 'What does the Issue model observer do, and which model lifecycle events does it handle?',
        'required_key' => 'models.observer_short_names.Issue',
        'assertions' => [
            'must_recall_from' => 'models.observer_short_names.Issue',
            'must_not_contain' => ['no observer', 'does not have an observer', 'observer does not exist'],
            'fact_keys' => ['models.observer_short_names.Issue'],
        ],
    ],

    [
        'id' => 'qa-003',
        'type' => 'qa',
        'prompt' => 'Which jobs in this application are queued, and what are their retry settings (queue name, tries, timeout)?',
        'required_key' => 'jobs.named',
        'assertions' => [
            'must_recall_from' => 'jobs.named',
            'must_not_contain' => ['no jobs', 'no queue'],
            'fact_keys' => ['jobs.named', 'jobs.queue.ArchiveClosedIssues', 'jobs.tries.ArchiveClosedIssues', 'jobs.queue.UpdateMilestoneProgress'],
        ],
    ],

    [
        'id' => 'qa-004',
        'type' => 'qa',
        'prompt' => 'What Eloquent casts are declared on the Project model?',
        'required_key' => 'models.cast_keys.Project',
        'assertions' => [
            'must_recall_from' => 'models.cast_keys.Project',
            'must_not_contain' => ['no casts', 'project model does not exist'],
            'fact_keys' => ['models.cast_keys.Project'],
        ],
    ],

    [
        'id' => 'qa-005',
        'type' => 'qa',
        'prompt' => 'Which Eloquent models have a corresponding policy registered in this application?',
        'required_key' => 'policies.models',
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
        'prompt' => 'Add a route to archive a single issue. Follow the route naming convention used in this application, apply the auth middleware, and add a matching authorize() call in the controller method.',
        'required_key' => 'routes.auth_required',
        'assertions' => [
            'must_contain' => ['auth', 'archive', 'authorize'],
            'must_not_contain' => ["Route::get('/issues'", "Route::get('/archive'"],
            'fact_keys' => ['routes.auth_required', 'routes.named'],
        ],
    ],

    [
        'id' => 'codegen-002',
        'type' => 'codegen',
        'prompt' => 'Add a `settings` cast to the Project model so the `settings` column is automatically cast to an array.',
        'required_key' => 'models.fillable.Project',
        'assertions' => [
            'must_contain' => ["'settings'", "'array'", 'Project'],
            'must_not_contain' => ['Settings::class', 'protected $casts = []'],
            'fact_keys' => ['models.casts.Project', 'models.fillable.Project'],
        ],
    ],

    [
        'id' => 'codegen-003',
        'type' => 'codegen',
        'prompt' => 'Write a FormRequest class for creating a new milestone. Include validation rules appropriate for the Milestone model fields in this application.',
        'required_key' => 'models.fillable.Milestone',
        'assertions' => [
            'must_recall_from' => 'models.fillable.Milestone',
            'must_contain' => ['FormRequest', 'rules'],
            'must_not_contain' => ['$request->validate(', 'Validator::make('],
            'fact_keys' => ['models.fillable.Milestone'],
        ],
    ],

    [
        'id' => 'codegen-004',
        'type' => 'codegen',
        'prompt' => 'Register a new queued listener for the IssueOpened event in this application. Show the listener class with a handle method and explain how to register it.',
        'required_key' => 'events.named',
        'assertions' => [
            'must_contain' => ['IssueOpened', 'ShouldQueue', 'handle'],
            'must_not_contain' => ['IssueAssigned', 'IssueCreated'],
            'fact_keys' => ['events.named'],
        ],
    ],

    // ─── Mini end-to-end tasks (3) ────────────────────────────────────────────

    [
        'id' => 'mini-001',
        'type' => 'mini',
        'prompt' => 'Add a feature to close all open issues in a project at once. Implement: a named route following this application\'s conventions, a controller method with proper policy authorization, and a queued job that performs the closing.',
        'required_key' => 'routes.auth_required',
        'assertions' => [
            'must_contain' => ['auth', 'authorize', 'dispatch', 'ShouldQueue'],
            'must_not_contain' => ["Route::get('/close'"],
            'fact_keys' => ['routes.named', 'policies.models'],
        ],
    ],

    [
        'id' => 'mini-002',
        'type' => 'mini',
        'prompt' => 'Add an email notification when a milestone is reached. Implement a listener that fires on the MilestoneReached event (which already exists in this application), a Mailable class, and configure the listener to be queued.',
        'required_key' => 'events.named',
        'assertions' => [
            'must_contain' => ['Mailable', 'ShouldQueue', 'handle', 'MilestoneReached'],
            'must_not_contain' => ['Mail::raw(', 'sendmail', 'MilestoneCompleted'],
            'fact_keys' => ['events.named'],
        ],
    ],

    [
        'id' => 'mini-003',
        'type' => 'mini',
        'prompt' => 'Expose a JSON API endpoint that lists open issues for a project. The endpoint must be authenticated, return data using an Eloquent API Resource, and follow the naming conventions used in this application.',
        'required_key' => 'routes.auth_required',
        'assertions' => [
            'must_contain' => ['JsonResource', 'auth', 'toArray'],
            'must_not_contain' => ['response()->json('],
            'fact_keys' => ['routes.named'],
        ],
    ],
];
