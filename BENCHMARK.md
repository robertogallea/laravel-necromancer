# necromancer:benchmark — AI Context Benchmark

`necromancer:benchmark` measures how much Necromancer's generated context file improves AI coding-assistant effectiveness on your codebase. It runs a bundled task suite in four conditions, scores each response automatically and optionally with an AI judge, and reports accuracy, hallucination rate, quality, latency, and token cost side by side.

---

## How it works

Every task in the suite runs once per **context condition**:

| Condition | Context injected |
|---|---|
| `none` | No context file — AI relies on prior training only |
| `manual` | A hand-written context file (default: `AGENTS.md`, configurable via `benchmark.manual_context_path`) |
| `necromancer` | The Necromancer-generated `NECROMANCER.md` |
| `necromancer-mcp` | No context file — instead, the model has access to four read-only tools that query the manifest live |

### The `necromancer-mcp` condition

Rather than reading a pre-assembled document, this condition gives the generation model the same query capability Necromancer's MCP server exposes — `query_routes`, `query_models`, `query_artifacts`, and `search_artifacts` — as four tools it can call during generation. It starts from the exact same bare instructions as `none` (no context is baked in at all), so any accuracy gain over `none` can be attributed to the model actively discovering facts via tool calls, not to information it was handed for free.

Despite the name, this condition doesn't start Necromancer's actual MCP server or use the MCP protocol — it uses `laravel/ai`-native tool implementations that mirror the same four query operations, so no `laravel/mcp` installation or running server is required. The name reflects the *capability* being approximated — what a real MCP-connected client like Claude Code or Cursor would have access to — not the wire mechanism underneath. The tool-calling loop is capped at 8 steps per task, pinned explicitly rather than left to `laravel/ai`'s framework-computed default, so runs stay reproducible across `laravel/ai` version upgrades.

Q&A tasks are an exception, but only for the *static* `necromancer` condition: because `NECROMANCER.md` is generated directly from the manifest, a Q&A task asking "which models have observers?" would trivially retrieve the answer from the context — the AI is just reading back what it was told. `necromancer-mcp` doesn't have this problem, since the model isn't handed the answer — it has to choose the right tool and interpret structured output — so Q&A tasks run under `none`, `manual`, and `necromancer-mcp`, and are excluded only from the static `necromancer` condition.

Each response is scored by:

1. **Automated fact-checker** — checks whether required strings appear and hallucination markers are absent, using assertions from the task suite.
2. **AI-as-judge** (optional) — a second AI call using a *different* model scores correctness, completeness, Laravel conventions, and conciseness on a 0–10 scale.

Wall-clock latency is measured independently around each AI call — the generation call, and separately the judge call when it runs — so a slow judge model is never mistaken for a slow generation model. Each condition's report shows the average latency (with standard deviation, when at least two results are available) for both.

The primary comparison is **Necromancer vs. manual** — proving the generated context outperforms a hand-written one, not just an empty context. A secondary comparison, **Necromancer (MCP) vs. Necromancer (static)**, shows whether live tool-querying discovers the same facts as effectively as reading the pre-generated document — see the comparison line in the example output below.

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
# Full benchmark — all 12 tasks × 4 conditions × AI judge
php artisan necromancer:benchmark

# Automated checks only (single provider, no judge cost)
php artisan necromancer:benchmark --no-judge

# Compare only no-context vs Necromancer
php artisan necromancer:benchmark --condition=none,necromancer --no-judge

# Compare live tool-querying against Necromancer's static context
php artisan necromancer:benchmark --condition=necromancer,necromancer-mcp --no-judge

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
  Tasks: 12  ·  Conditions: 4  ·  Model: claude-sonnet-4-6  ·  Judge: gpt-4o

  ─── Results ─────────────────────────────────────────────────────────
  Condition              Accuracy   Halluc.    Quality    Tokens        Latency  Judge Latency
  No context               41%        23%        5.1       1 200   0.9s ± 0.2s    1.4s ± 0.3s
  Manual AGENTS.md         67%         9%        6.8       2 100   1.3s ± 0.3s    1.5s ± 0.2s
  Necromancer              89%         2%        8.4       1 950   2.1s ± 0.5s    1.6s ± 0.4s
  Necromancer (MCP)        85%         1%        7.9       1 480   3.4s ± 0.8s    1.6s ± 0.3s
  ──────────────────────────────────────────────────────────────────────

  Necromancer vs manual:  +22pp accuracy · +7pp fewer hallucinations

  Necromancer (MCP) vs Necromancer (static):  -4pp accuracy · +1pp fewer hallucinations
```

The `Judge Latency` column only appears when at least one result in the run actually has judge data — it's omitted entirely (not shown blank) on a `--no-judge` run. The `± Y.Ys` suffix is the sample standard deviation and is likewise omitted when fewer than two results are available for that condition. The `Necromancer (MCP) vs Necromancer (static)` line only appears when both conditions are present in the run.

`--format=markdown` renders the same data as a table, with the same Latency/Judge Latency columns and omission rule:

```bash
php artisan necromancer:benchmark --format=markdown --output=benchmark.md
```

```markdown
# Necromancer Benchmark Results

| Condition | Accuracy | Hallucination Rate | Quality Score | Avg Tokens | Latency | Judge Latency |
|---|---|---|---|---|---|---|
| No context | 41% | 23% | 5.1 / 10 | 1200 | 0.9s ± 0.2s | 1.4s ± 0.3s |
| Manual CLAUDE.md | 67% | 9% | 6.8 / 10 | 2100 | 1.3s ± 0.3s | 1.5s ± 0.2s |
| Necromancer | 89% | 2% | 8.4 / 10 | 1950 | 2.1s ± 0.5s | 1.6s ± 0.4s |
| Necromancer (MCP) | 85% | 1% | 7.9 / 10 | 1480 | 3.4s ± 0.8s | 1.6s ± 0.3s |

**Necromancer vs manual:** +22pp accuracy · +7pp hallucination reduction

**Necromancer (MCP) vs Necromancer (static):** -4pp accuracy · +1pp hallucination reduction
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
        ├── qa-001__necromancer-mcp.md
        └── ...           # one file per task × condition
```

### `run.json`

Run-level metadata and aggregated statistics:

```json
{
    "started_at": "2026-06-06T11:51:53Z",
    "manifest": { "path": "necromancer.json", "bytes": 12480, "sha256": "e3b0c..." },
    "conditions": ["none", "manual", "necromancer", "necromancer-mcp"],
    "types": null,
    "generation": { "model": "claude-sonnet-4-6", "provider": "anthropic" },
    "judge": { "enabled": true, "model": "gpt-4o", "provider": "openai" },
    "timeout": 120,
    "contexts": {
        "manual":      { "path": "AGENTS.md",     "exists": true, "bytes": 840,  "sha256": "..." },
        "necromancer": { "path": "NECROMANCER.md", "exists": true, "bytes": 4210, "sha256": "..." }
    },
    "summary": {
        "none":            { "accuracy": 0.41, "hallucinationRate": 0.23, "qualityScore": 5.1, "avgPromptTokens": 55,   "avgCompletionTokens": 110, "totalJudgeTokens": 4200, "avgLatencyMs": 900,  "latencyStdDevMs": 200, "avgJudgeLatencyMs": 1400, "judgeLatencyStdDevMs": 300 },
        "manual":          { "accuracy": 0.67, "hallucinationRate": 0.09, "qualityScore": 6.8, "avgPromptTokens": 840,  "avgCompletionTokens": 130, "totalJudgeTokens": 4900, "avgLatencyMs": 1300, "latencyStdDevMs": 300, "avgJudgeLatencyMs": 1500, "judgeLatencyStdDevMs": 200 },
        "necromancer":     { "accuracy": 0.89, "hallucinationRate": 0.02, "qualityScore": 8.4, "avgPromptTokens": 3450, "avgCompletionTokens": 150, "totalJudgeTokens": 5100, "avgLatencyMs": 2100, "latencyStdDevMs": 500, "avgJudgeLatencyMs": 1600, "judgeLatencyStdDevMs": 400 },
        "necromancer-mcp": { "accuracy": 0.85, "hallucinationRate": 0.01, "qualityScore": 7.9, "avgPromptTokens": 1330, "avgCompletionTokens": 150, "totalJudgeTokens": 4800, "avgLatencyMs": 3400, "latencyStdDevMs": 800, "avgJudgeLatencyMs": 1600, "judgeLatencyStdDevMs": 300 }
    },
    "warnings": []
}
```

`summary` mirrors `BenchmarkReport::byCondition()`'s in-memory shape verbatim, so its keys are camelCase — unlike the per-task fields below, which use snake_case. `avgJudgeLatencyMs`/`judgeLatencyStdDevMs` are computed only over results that actually carry judge data, same as `qualityScore`.

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
            "latency_ms":             823,
            "judge_tokens":           711,
            "judge_latency_ms":       1412,
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
prompt_tokens: 44 · completion_tokens: 119 · latency_ms: 823
judge_tokens: 711 · judge_latency_ms: 1412
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
| `--condition=*` | Conditions to run: `none`, `manual`, `necromancer`, `necromancer-mcp`. Default: all four. |
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
    'conditions' => ['none', 'manual'],   // skip the necromancer and necromancer-mcp conditions
    'assertions' => [...],
]
```

Omitting `conditions` (or setting it to `null`) means the task runs under all active conditions. All built-in Q&A tasks set `['none', 'manual', 'necromancer-mcp']` by default — excluding only the static `necromancer` condition, whose context already contains the answer verbatim.

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
| Q&A tasks trivially score 100% under Necromancer (the answer is literally in the context file) | Q&A tasks only run under `none`, `manual`, and `necromancer-mcp` — the static `necromancer` condition is excluded by default via the `conditions` field |
| Manifest-derived golden answers favour Necromancer | Each `fact_key` is cross-checked against the framework runtime (`Route::getRoutes()`, `class_exists()`) before use; mismatches are flagged in the report |
| AI judge favours its own output style | Generation and judge use **different models** (e.g. Claude generates, GPT-4o judges) |
| No-context is an unfair baseline | The **manual vs. necromancer** comparison is the primary claim; no-context is a lower bound only |
| Latency differences look like a speed verdict on the model | The `none`/`manual`/`necromancer` context files differ in size, so a slower `necromancer` condition likely reflects a longer prompt, not a slower model — latency is reported for diagnostics, not as an accuracy/quality-style comparison claim |
| `necromancer-mcp`'s `Tokens` figure understates true cost when the model makes more than one tool call | `laravel/ai`'s streaming events only expose token usage for the final tool-calling round — intermediate rounds' usage isn't visible, so this condition's token figure is a lower bound, not an apples-to-apples comparison with the other three conditions |

---

## Requires

- `laravel/ai` installed and configured (`composer require laravel/ai`)
- At least one AI provider in `config/ai.php`
- `necromancer.json` present (run `php artisan necromancer:scan` first)
- For the `manual` condition: an `AGENTS.md` at the project root (default) or the path set in `benchmark.manual_context_path`
- The `necromancer-mcp` condition does **not** require `laravel/mcp` — despite the name, it uses native `laravel/ai` tool implementations rather than the MCP protocol, so no MCP server needs to be running
