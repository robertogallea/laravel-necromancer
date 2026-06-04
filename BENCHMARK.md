# necromancer:benchmark — AI Context Benchmark

`necromancer:benchmark` measures how much Necromancer's generated context file improves AI coding-assistant effectiveness on your codebase. It runs a bundled task suite in three conditions, scores each response automatically and optionally with an AI judge, and reports accuracy, hallucination rate, quality, and token cost side by side.

---

## How it works

Every task in the suite runs three times — once per **context condition**:

| Condition | Context injected |
|---|---|
| `none` | No context file — AI relies on prior training only |
| `manual` | A hand-written `CLAUDE.md` (your current baseline) |
| `necromancer` | The Necromancer-generated `NECROMANCER.md` |

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

The `manual` condition reads `CLAUDE.md` at the project root. Create a hand-written context file that represents what a developer would typically maintain:

```bash
# Create a minimal hand-written context file
# (intentionally less complete than the generated one — that's the point)
touch CLAUDE.md
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
  Manual CLAUDE.md         67%         9%        6.8       2 100
  Necromancer              89%         2%        8.4       1 950
  ──────────────────────────────────────────────────────────────────────

  Necromancer vs manual:  +22pp accuracy · +7pp fewer hallucinations
```

---

## Options reference

| Option | Description |
|---|---|
| `--condition=*` | Conditions to run: `none`, `manual`, `necromancer`. Default: all three. |
| `--type=*` | Task types: `qa`, `codegen`, `mini`. Default: all. |
| `--no-judge` | Skip the AI-as-judge pass (automated checks only). |
| `--model=` | Override generation model. |
| `--judge=` | Override judge model. |
| `--format=` | Output format: `text` (default), `markdown`, `json`. |
| `--output=PATH` | Write the report to a file instead of the terminal. |

---

## Config reference (`config/necromancer.php`)

```php
'benchmark' => [
    'manual_context_path' => base_path('CLAUDE.md'),          // path to the hand-written baseline
    'generation_model'    => env('NECROMANCER_BENCH_MODEL', 'claude-sonnet-4-6'),
    'generation_provider' => env('NECROMANCER_BENCH_PROVIDER'),
    'judge_model'         => env('NECROMANCER_BENCH_JUDGE', 'gpt-4o'),
    'judge_provider'      => env('NECROMANCER_BENCH_JUDGE_PROVIDER'),
    'tasks'               => [],  // override with a custom task suite
],
```

---

## Task suite

The bundled suite targets the **Laraboard** demo app (included with the package). It contains 12 tasks:

| Category | Count | Tests |
|---|---|---|
| Q&A | 5 | Route auth, model observers, job retry config, model casts, policies |
| Code generation | 4 | Adding routes, model casts, FormRequests, event listeners |
| Mini end-to-end | 3 | Multi-step features combining routes, jobs, listeners, and resources |

Each task carries `must_contain` / `must_not_contain` string assertions for automated scoring and `fact_keys` for manifest-grounded golden answers.

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
| Manifest-derived golden answers favour Necromancer | Each `fact_key` is cross-checked against the framework runtime (`Route::getRoutes()`, `class_exists()`) before use; mismatches are flagged in the report |
| AI judge favours its own output style | Generation and judge use **different models** (e.g. Claude generates, GPT-4o judges) |
| No-context is an unfair baseline | The **manual vs. necromancer** comparison is the primary claim; no-context is a lower bound only |

---

## Requires

- `laravel/ai` installed and configured (`composer require laravel/ai`)
- At least one AI provider in `config/ai.php`
- `necromancer.json` present (run `php artisan necromancer:scan` first)
- For the `manual` condition: a `CLAUDE.md` at `benchmark.manual_context_path`
