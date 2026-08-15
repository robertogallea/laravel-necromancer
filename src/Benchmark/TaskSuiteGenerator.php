<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

final class TaskSuiteGenerator
{
    /** @param array<string, mixed> $manifest */
    public function __construct(private readonly array $manifest) {}

    /** @return array<int, array<string, mixed>> */
    public function generate(): array
    {
        $artifacts = (array) ($this->manifest['artifacts'] ?? []);

        $authRoutes = $this->authRouteNames($artifacts);
        $namedRoutes = $this->namedRouteNames($artifacts);
        $observedModel = $this->firstModelWithObservers($artifacts);
        $castModel = $this->firstModelWithCasts($artifacts);
        $fillableModel = $this->firstModelWithFillable($artifacts);
        $jobs = $this->jobNames($artifacts);
        $firstJob = $jobs[0] ?? null;
        $firstEvent = $this->firstEventName($artifacts);

        return [
            $this->qa001(),
            $this->qa002($observedModel),
            $this->qa003($jobs, $firstJob),
            $this->qa004($castModel),
            $this->qa005(),
            $this->codegen001(),
            $this->codegen002($castModel),
            $this->codegen003($fillableModel),
            $this->codegen004($firstEvent),
            $this->mini001(),
            $this->mini002($firstEvent),
            $this->mini003(),
        ];
    }

    /** @return array<string, mixed> */
    private function qa001(): array
    {
        return [
            'id' => 'qa-001',
            'type' => 'qa',
            'prompt' => 'What routes in this application require authentication? List their names and HTTP methods.',
            'required_key' => 'routes.auth_required',
            'conditions' => ['none', 'manual', 'necromancer-mcp'],
            'assertions' => [
                'must_recall_from' => 'routes.auth_required',
                'fact_keys' => ['routes.auth_required'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function qa002(?string $model): array
    {
        if ($model === null) {
            return [
                'id' => 'qa-002',
                'type' => 'qa',
                'prompt' => 'Which Eloquent models in this application have observers attached? List the observer class names.',
                'required_key' => 'models.with_observers',
                'conditions' => ['none', 'manual', 'necromancer-mcp'],
                'assertions' => [
                    'must_recall_from' => 'models.with_observers',
                    'must_not_contain' => ['no observer', 'does not have', 'observer does not exist'],
                    'fact_keys' => ['models.with_observers'],
                ],
            ];
        }

        return [
            'id' => 'qa-002',
            'type' => 'qa',
            'prompt' => "What does the {$model} model observer do, and which lifecycle events does it handle?",
            'required_key' => "models.observer_short_names.{$model}",
            'conditions' => ['none', 'manual', 'necromancer-mcp'],
            'assertions' => [
                'must_recall_from' => "models.observer_short_names.{$model}",
                'must_not_contain' => ['no observer', 'does not have an observer', 'observer does not exist'],
                'fact_keys' => ["models.observer_short_names.{$model}"],
            ],
        ];
    }

    /**
     * @param  string[]  $jobs
     * @return array<string, mixed>
     */
    private function qa003(array $jobs, ?string $firstJob): array
    {
        $factKeys = ['jobs.named'];
        if ($firstJob !== null) {
            $factKeys[] = "jobs.queue.{$firstJob}";
            $factKeys[] = "jobs.tries.{$firstJob}";
        }

        return [
            'id' => 'qa-003',
            'type' => 'qa',
            'prompt' => 'What jobs exist in this application, and what are their queue names and retry settings?',
            'required_key' => 'jobs.named',
            'conditions' => ['none', 'manual', 'necromancer-mcp'],
            'assertions' => [
                'must_recall_from' => 'jobs.named',
                'must_not_contain' => ['no jobs', 'no queue'],
                'fact_keys' => $factKeys,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function qa004(?string $model): array
    {
        if ($model === null) {
            return [
                'id' => 'qa-004',
                'type' => 'qa',
                'prompt' => 'Which Eloquent models in this application declare casts? List the model names.',
                'required_key' => 'models.with_casts',
                'conditions' => ['none', 'manual', 'necromancer-mcp'],
                'assertions' => [
                    'must_recall_from' => 'models.with_casts',
                    'must_not_contain' => ['no casts', 'no models'],
                    'fact_keys' => ['models.with_casts'],
                ],
            ];
        }

        return [
            'id' => 'qa-004',
            'type' => 'qa',
            'prompt' => "What Eloquent casts are declared on the {$model} model?",
            'required_key' => "models.cast_keys.{$model}",
            'conditions' => ['none', 'manual', 'necromancer-mcp'],
            'assertions' => [
                'must_recall_from' => "models.cast_keys.{$model}",
                'must_not_contain' => ['no casts', "{$model} model does not exist"],
                'fact_keys' => ["models.cast_keys.{$model}"],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function qa005(): array
    {
        return [
            'id' => 'qa-005',
            'type' => 'qa',
            'prompt' => 'Which Eloquent models have a corresponding policy registered in this application?',
            'required_key' => 'policies.models',
            'conditions' => ['none', 'manual', 'necromancer-mcp'],
            'assertions' => [
                'must_recall_from' => 'policies.models',
                'must_not_contain' => ['no policies'],
                'fact_keys' => ['policies.models'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function codegen001(): array
    {
        return [
            'id' => 'codegen-001',
            'type' => 'codegen',
            'prompt' => 'Add a new route to this application following the existing naming convention. Apply the auth middleware and add a matching authorize() call in the controller method.',
            'required_key' => 'routes.named',
            'assertions' => [
                'must_contain' => ['auth', 'authorize'],
                'fact_keys' => ['routes.named'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function codegen002(?string $model): array
    {
        if ($model === null) {
            return [
                'id' => 'codegen-002',
                'type' => 'codegen',
                'prompt' => 'Add an array cast to a model in this application. Show the correct $casts syntax following the casting conventions already used.',
                'required_key' => 'models.with_casts',
                'assertions' => [
                    'must_contain' => ["'array'"],
                    'must_not_contain' => ['protected $casts = []'],
                    'fact_keys' => ['models.with_casts'],
                ],
            ];
        }

        return [
            'id' => 'codegen-002',
            'type' => 'codegen',
            'prompt' => "Add a new array cast to the {$model} model. Show the correct \$casts syntax following the conventions already used in this model.",
            'required_key' => "models.cast_keys.{$model}",
            'assertions' => [
                'must_contain' => ["'array'", $model],
                'must_not_contain' => ['protected $casts = []'],
                'fact_keys' => ["models.cast_keys.{$model}"],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function codegen003(?string $model): array
    {
        $prompt = $model !== null
            ? "Write a FormRequest class for creating a new {$model}. Include validation rules appropriate for the {$model} model's fillable fields."
            : "Write a FormRequest class for creating a new resource in this application. Include validation rules appropriate for an existing model's fillable fields.";

        return [
            'id' => 'codegen-003',
            'type' => 'codegen',
            'prompt' => $prompt,
            'required_key' => $model !== null ? "models.fillable.{$model}" : 'routes.auth_required',
            'assertions' => [
                'must_recall_from' => $model !== null ? "models.fillable.{$model}" : null,
                'must_contain' => ['FormRequest', 'rules'],
                'must_not_contain' => ['$request->validate(', 'Validator::make('],
                'fact_keys' => $model !== null ? ["models.fillable.{$model}"] : ['routes.named'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function codegen004(?string $event): array
    {
        if ($event === null) {
            return [
                'id' => 'codegen-004',
                'type' => 'codegen',
                'prompt' => 'Register a new queued listener for one of the events in this application. Show the listener class with a handle method and explain how to register it.',
                'required_key' => 'events.named',
                'assertions' => [
                    'must_recall_from' => 'events.named',
                    'must_contain' => ['ShouldQueue', 'handle'],
                    'fact_keys' => ['events.named'],
                ],
            ];
        }

        return [
            'id' => 'codegen-004',
            'type' => 'codegen',
            'prompt' => "Register a new queued listener for the {$event} event in this application. Show the listener class with a handle method and explain how to register it.",
            'required_key' => 'events.named',
            'assertions' => [
                'must_contain' => [$event, 'ShouldQueue', 'handle'],
                'fact_keys' => ['events.named'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mini001(): array
    {
        return [
            'id' => 'mini-001',
            'type' => 'mini',
            'prompt' => "Add a feature to perform a bulk operation on a resource in this application. Implement: a named route following this application's conventions, a controller method with policy authorization, and a queued job that performs the operation.",
            'required_key' => 'routes.auth_required',
            'assertions' => [
                'must_contain' => ['auth', 'authorize', 'dispatch', 'ShouldQueue'],
                'fact_keys' => ['routes.named', 'policies.models'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mini002(?string $event): array
    {
        if ($event === null) {
            return [
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
            ];
        }

        return [
            'id' => 'mini-002',
            'type' => 'mini',
            'prompt' => "Add an email notification when the {$event} event fires. Implement a queued listener, a Mailable class, and show the registration.",
            'required_key' => 'events.named',
            'assertions' => [
                'must_contain' => [$event, 'Mailable', 'ShouldQueue', 'handle'],
                'must_not_contain' => ['Mail::raw(', 'sendmail'],
                'fact_keys' => ['events.named'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mini003(): array
    {
        return [
            'id' => 'mini-003',
            'type' => 'mini',
            'prompt' => 'Expose a JSON API endpoint that lists resources for one of the authenticated routes in this application. The endpoint must be authenticated, return data using an Eloquent API Resource, and follow the naming conventions used.',
            'required_key' => 'routes.auth_required',
            'assertions' => [
                'must_contain' => ['JsonResource', 'auth', 'toArray'],
                'must_not_contain' => ['response()->json('],
                'fact_keys' => ['routes.named'],
            ],
        ];
    }

    // ─── Manifest helpers ────────────────────────────────────────────────────

    /** @return string[] */
    private function authRouteNames(array $artifacts): array
    {
        return array_values(array_filter(array_map(
            function (array $r): ?string {
                if (! filled($r['name'] ?? null)) {
                    return null;
                }
                $hasAuth = array_filter(
                    (array) ($r['middleware'] ?? []),
                    fn (string $m): bool => $m === 'auth' || str_starts_with($m, 'auth:')
                );

                return $hasAuth ? (string) $r['name'] : null;
            },
            (array) ($artifacts['routes'] ?? [])
        )));
    }

    /** @return string[] */
    private function namedRouteNames(array $artifacts): array
    {
        return array_values(array_filter(array_map(
            fn (array $r): ?string => filled($r['name'] ?? null) ? (string) $r['name'] : null,
            (array) ($artifacts['routes'] ?? [])
        )));
    }

    private function firstModelWithObservers(array $artifacts): ?string
    {
        foreach ((array) ($artifacts['models'] ?? []) as $model) {
            if (! empty($model['observers'])) {
                return $this->shortName((string) ($model['class'] ?? ''));
            }
        }

        return null;
    }

    private function firstModelWithCasts(array $artifacts): ?string
    {
        foreach ((array) ($artifacts['models'] ?? []) as $model) {
            if (! empty($model['casts'])) {
                return $this->shortName((string) ($model['class'] ?? ''));
            }
        }

        return null;
    }

    private function firstModelWithFillable(array $artifacts): ?string
    {
        foreach ((array) ($artifacts['models'] ?? []) as $model) {
            if (! empty($model['fillable'])) {
                return $this->shortName((string) ($model['class'] ?? ''));
            }
        }

        return null;
    }

    /** @return string[] */
    private function jobNames(array $artifacts): array
    {
        return array_values(array_filter(array_map(
            fn (array $j): ?string => isset($j['class']) ? $this->shortName((string) $j['class']) : null,
            (array) ($artifacts['jobs'] ?? [])
        )));
    }

    private function firstEventName(array $artifacts): ?string
    {
        $events = (array) ($artifacts['events'] ?? []);

        return isset($events[0]['class']) ? $this->shortName((string) $events[0]['class']) : null;
    }

    private function shortName(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
