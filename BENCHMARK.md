# necromancer:benchmark — AI Context Benchmark

`necromancer:benchmark` measures how much Necromancer's generated context file improves AI coding-assistant effectiveness on your codebase. It runs a bundled task suite in three conditions, scores each response automatically and optionally with an AI judge, and reports accuracy, hallucination rate, quality, and token cost side by side.

---

## How it works

Every task in the suite runs once per **context condition**:

| Condition | Context injected |
|---|---|
| `none` | No context file — AI relies on prior training only |
| `manual` | A hand-written context file (default: `AGENTS.md`, configurable via `benchmark.manual_context_path`) |
| `necromancer` | The Necromancer-generated `NECROMANCER.md` |

Q&A tasks are an exception: they only run under `none` and `manual`. Because `NECROMANCER.md` is generated directly from the manifest, a Q&A task asking "which models have observers?" would trivially retrieve the answer from the context — the AI is just reading back what it was told. The meaningful measurement there is how well a hand-written `AGENTS.md` covers the same facts.

Each response is scored by:

1. **Automated fact-checker** — checks whether required strings appear and hallucination markers are absent, using assertions from the task suite.
2. **AI-as-judge** (optional) — a second AI call using a *different* model scores correctness, completeness, Laravel conventions, and conciseness on a 0–10 scale.

The primary comparison is **Necromancer vs. manual** — proving the generated context outperforms a hand-written one, not just an empty context.

---

## Setup

### 1. Publish and configure

```bash
php artisan vendor:publish --tag=necromancer-config
```

Add to `.env`:

```env
# Generation model (the one answering the tasks)
NECROMANCER_BENCH_MODEL=claude-sonnet-4-6
NECROMANCER_BENCH_PROVIDER=anthropic

# Judge model (cross-model to avoid self-serving bias)
NECROMANCER_BENCH_JUDGE=gpt-4o
NECROMANCER_BENCH_JUDGE_PROVIDER=openai
```

Both providers must be configured in `config/ai.php`. If you only have one provider, use `--no-judge` (see below).

### 2. Write your manual baseline

The `manual` condition reads `AGENTS.md` at the project root by default (configurable via `benchmark.manual_context_path` in `config/necromancer.php`). Create a hand-written context file that represents what a developer would typically maintain:

```bash
# Create a minimal hand-written context file
# (intentionally less complete than the generated one — that's the point)
touch AGENTS.md
```

### 3. Scan and generate

```bash
php artisan necromancer:scan
php artisan necromancer:generate
```

---

## Running the benchmark

```bash
# Full benchmark — all 12 tasks × 3 conditions × AI judge
php artisan necromancer:benchmark

# Automated checks only (single provider, no judge cost)
php artisan necromancer:benchmark --no-judge

# Compare only no-context vs Necromancer
php artisan necromancer:benchmark --condition=none,necromancer --no-judge

# Q&A tasks only (fastest)
php artisan necromancer:benchmark --type=qa --no-judge

# Export results for slides or a paper appendix
php artisan necromancer:benchmark --format=markdown --output=benchmark.md
php artisan necromancer:benchmark --format=json --output=results.json
```

### Recommended first run

```bash
# 1. Validate setup cheaply
php artisan necromancer:benchmark --no-judge --condition=none,necromancer --type=qa

# 2. Full benchmark when both providers are configured
php artisan necromancer:benchmark --format=markdown --output=benchmark.md
```

---

## Example output

```
  Laravel Necromancer — Benchmark
  ────────────────────────────────
  Tasks: 12  ·  Conditions: 3  ·  Model: claude-sonnet-4-6  ·  Judge: gpt-4o

  ─── Results ─────────────────────────────────────────────────────────
  Condition              Accuracy   Halluc.    Quality    Tokens
  No context               41%        23%        5.1       1 200
  Manual AGENTS.md         67%         9%        6.8       2 100
  Necromancer              89%         2%        8.4       1 950
  ──────────────────────────────────────────────────────────────────────

  Necromancer vs manual:  +22pp accuracy · +7pp fewer hallucinations
```

---

## Benchmark dumps

Every run writes a timestamped directory to `storage/app/necromancer/benchmarks/` (configurable). Use `--no-dump` to suppress writes.

```
storage/app/necromancer/benchmarks/
└── 2026-06-06-115153/
    ├── run.json          # run metadata + per-condition summary
    ├── results.json      # all per-task results
    └── responses/
        ├── qa-001__none.md
        ├── qa-001__manual.md
        ├── qa-001__necromancer.md
        └── ...           # one file per task × condition
```

### `run.json`

Run-level metadata and aggregated statistics:

```json
{
    "started_at": "2026-06-06T11:51:53Z",
    "manifest": { "path": "necromancer.json", "bytes": 12480, "sha256": "e3b0c..." },
    "conditions": ["none", "manual", "necromancer"],
    "types": null,
    "generation": { "model": "claude-sonnet-4-6", "provider": "anthropic" },
    "judge": { "enabled": true, "model": "gpt-4o", "provider": "openai" },
    "timeout": 120,
    "contexts": {
        "manual":      { "path": "AGENTS.md",     "exists": true, "bytes": 840,  "sha256": "..." },
        "necromancer": { "path": "NECROMANCER.md", "exists": true, "bytes": 4210, "sha256": "..." }
    },
    "summary": {
        "none":        { "accuracy": 0.41, "hallucination_rate": 0.23, "quality_score": 5.1, "avg_prompt_tokens": 55,   "avg_completion_tokens": 110 },
        "manual":      { "accuracy": 0.67, "hallucination_rate": 0.09, "quality_score": 6.8, "avg_prompt_tokens": 840,  "avg_completion_tokens": 130 },
        "necromancer": { "accuracy": 0.89, "hallucination_rate": 0.02, "quality_score": 8.4, "avg_prompt_tokens": 3450, "avg_completion_tokens": 150 }
    },
    "warnings": []
}
```

### `results.json`

Full per-task breakdown, useful for deeper analysis or feeding a charting tool:

```json
{
    "summary": { "..." },
    "results": [
        {
            "task_id":                "qa-001",
            "task_type":              "qa",
            "condition":              "none",
            "prompt":                 "What routes require authentication?",
            "response":               "Based on the Laravel application...",
            "skipped":                false,
            "skip_reason":            null,
            "accuracy":               0.0,
            "hallucination_rate":     0.0,
            "judge_score":            5,
            "prompt_tokens":          44,
            "completion_tokens":      119,
            "judge_tokens":           711,
            "golden_answers_trusted": true
        }
    ]
}
```

`skipped: true` means the task was skipped because its `required_key` was absent from the manifest. `golden_answers_trusted: false` means the manifest-derived golden answers could not be cross-checked against the framework runtime.

### `responses/{task_id}__{condition}.md`

One Markdown file per task × condition — human-readable, ready to paste into a PR or paper appendix:

```
# qa-001 / none

type: qa · skipped: false
accuracy: 0.00 · hallucination_rate: 0.00 · judge_score: 5
prompt_tokens: 44 · completion_tokens: 119 · judge_tokens: 711
golden_answers_trusted: true

## Prompt

What routes require authentication?

## Response

Based on the Laravel application...
```

### Controlling dump output

Add to `.env` to change dump behaviour:

```env
NECROMANCER_BENCH_DUMP_ENABLED=false          # disable entirely
NECROMANCER_BENCH_DUMP_PATH=/tmp/bench-runs   # write to a custom path
```

Or use `--no-dump` per run:

```bash
php artisan necromancer:benchmark --no-dump
```

---

## Options reference

| Option | Description |
|---|---|
| `--condition=*` | Conditions to run: `none`, `manual`, `necromancer`. Default: all three. |
| `--type=*` | Task types: `qa`, `codegen`, `mini`. Default: all. |
| `--no-judge` | Skip the AI-as-judge pass (automated checks only). |
| `--no-dump` | Skip writing the per-run dump to `storage/`. |
| `--model=` | Override generation model. |
| `--judge=` | Override judge model. |
| `--timeout=` | HTTP timeout per AI call in seconds. Default: 120. |
| `--format=` | Output format: `text` (default), `markdown`, `json`. |
| `--output=PATH` | Write the report to a file instead of the terminal. |
| `--generate-suite` | Generate a grounded task suite from the manifest and write it to `config/benchmark-tasks.php`. Exits without running the benchmark. |
| `--suite-output=PATH` | Path for the generated suite file. Default: `config/benchmark-tasks.php`. |

---

## Config reference (`config/necromancer.php`)

```php
'benchmark' => [
    'manual_context_path' => base_path('AGENTS.md'),          // default; override to point at CLAUDE.md or any other file
    'generation_model'    => env('NECROMANCER_BENCH_MODEL', 'claude-sonnet-4-6'),
    'generation_provider' => env('NECROMANCER_BENCH_PROVIDER'),
    'judge_model'         => env('NECROMANCER_BENCH_JUDGE', 'gpt-4o'),
    'judge_provider'      => env('NECROMANCER_BENCH_JUDGE_PROVIDER'),
    'timeout'             => env('NECROMANCER_BENCH_TIMEOUT', 120),
    'dump' => [
        'enabled' => env('NECROMANCER_BENCH_DUMP_ENABLED', true),  // set to false to suppress writes
        'path'    => env('NECROMANCER_BENCH_DUMP_PATH'),            // defaults to storage/app/necromancer/benchmarks
    ],
    'tasks' => [],  // override with a custom task suite
],
```

---

## Task suite

The bundled suite is **generic** — it works on any Laravel application by using manifest-derived fact keys (`routes.auth_required`, `models.with_observers`, etc.) and skipping tasks whose `required_key` is absent from the manifest. It contains 12 tasks:

| Category | Count | Tests |
|---|---|---|
| Q&A | 5 | Route auth, model observers, job retry config, model casts, policies |
| Code generation | 4 | Adding routes, model casts, FormRequests, event listeners |
| Mini end-to-end | 3 | Multi-step features combining routes, jobs, listeners, and resources |

Each task carries `must_contain` / `must_not_contain` string assertions for automated scoring and `fact_keys` for manifest-grounded golden answers. Tasks can also carry a `conditions` key to restrict which conditions they run under:

```php
[
    'id'         => 'qa-001',
    'type'       => 'qa',
    'prompt'     => '...',
    'conditions' => ['none', 'manual'],   // skip the necromancer condition
    'assertions' => [...],
]
```

Omitting `conditions` (or setting it to `null`) means the task runs under all active conditions. All built-in Q&A tasks set `['none', 'manual']` by default.

### Generating a grounded suite

The generic suite scores loosely — it checks that *some* observer name appears in the response. A **grounded** suite picks the actual model, event, and job names from your manifest, making assertions far more discriminating.

Generate one automatically after scanning:

```bash
php artisan necromancer:scan
php artisan necromancer:benchmark --generate-suite
```

This writes `config/benchmark-tasks.php` with prompts and assertions tailored to your application's real artifacts (e.g. `"What does the Order model observer do?"` instead of a generic observer question). Wire it up in `config/necromancer.php`:

```php
'benchmark' => [
    'tasks' => require __DIR__.'/benchmark-tasks.php',
],
```

Write to a custom path with `--suite-output`:

```bash
php artisan necromancer:benchmark --generate-suite --suite-output=config/my-tasks.php
```

The generated file is plain PHP — commit it and re-generate after `necromancer:scan` updates the manifest.

### Custom task suite

Point the `tasks` config key at your own PHP file returning a task array:

```php
'benchmark' => [
    'tasks' => require base_path('my-benchmark-tasks.php'),
],
```

Each task must follow this shape:

```php
[
    'id'         => 'qa-001',
    'type'       => 'qa',          // qa | codegen | mini
    'prompt'     => 'What routes require authentication?',
    'assertions' => [
        'must_contain'     => ['auth', 'projects.index'],
        'must_not_contain' => ["Route::get('/projects'"],
        'fact_keys'        => ['routes.named'],
    ],
]
```

`fact_keys` use dot-notation (`{type}.{field}.{ShortClassName}`) and are resolved from the manifest at runtime, making the suite portable across apps.

---

## Bias mitigations

| Risk | Mitigation |
|---|---|
| Q&A tasks trivially score 100% under Necromancer (the answer is literally in the context file) | Q&A tasks only run under `none` and `manual` — the Necromancer condition is excluded by default via the `conditions` field |
| Manifest-derived golden answers favour Necromancer | Each `fact_key` is cross-checked against the framework runtime (`Route::getRoutes()`, `class_exists()`) before use; mismatches are flagged in the report |
| AI judge favours its own output style | Generation and judge use **different models** (e.g. Claude generates, GPT-4o judges) |
| No-context is an unfair baseline | The **manual vs. necromancer** comparison is the primary claim; no-context is a lower bound only |

---

## Requires

- `laravel/ai` installed and configured (`composer require laravel/ai`)
- At least one AI provider in `config/ai.php`
- `necromancer.json` present (run `php artisan necromancer:scan` first)
- For the `manual` condition: an `AGENTS.md` at the project root (default) or the path set in `benchmark.manual_context_path`
