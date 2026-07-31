# Changelog

All notable changes to `laravel-necromancer` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.3.0

### Added

- `Necromancer::forMetadata()` facade helper (`LaravelNecromancer\Facades\Necromancer`, backed by `LaravelNecromancer\Metadata\RouteMetadataFactory`) as a shorthand for building the `route_metadata` array passed to `Route::metadata()`. Accepts `domain`, `flow`, `capability`, `summary`, `risk`, `externalServices` (string or array), and `adr` as named arguments, wraps them under the configured `route_metadata.namespace`, and drops any field left null — callers no longer need to remember the namespace key or filter empty values by hand. Produces the exact same shape as the raw-array form; has no effect on collection or normalization.

## 1.2.0

### Added

- Route metadata support via Laravel 13.17's `Route::metadata()`/`getMetadata()`. `RouteCollector` feature-detects the API via `method_exists()` and omits the field entirely on earlier Laravel versions — no parallel metadata system, no error. Routes gain an optional `route_metadata` manifest field: `raw` (the full resolved metadata, whatever the namespace) and `necromancer` (a normalized view of `domain`, `flow`, `capability`, `summary`, `risk`, `external_services`, and `adr`, read from the reserved `necromancer` namespace key so other packages' route metadata is never misread as a Necromancer signal).
- `route_metadata.namespace` config key (defaults to `necromancer`) controlling which namespace key is normalized.
- `Route Metadata Coverage` doctor dimension (10% weight): scores domain-tag coverage, ADR references on high/critical-risk routes, test evidence on external-service routes, and domain/risk consistency across routes sharing a `flow`, among routes that have opted in. Scores N/A (no penalty) until at least one route declares `necromancer` metadata, since adoption is optional.
- `HighRiskRoutesWithoutAdrCheck`, `ExternalServiceRoutesWithoutTestsCheck`, `NarrativeRouteMetadataSummaryCheck`, and `InconsistentFlowMetadataCheck` audit checks, flagging high/critical-risk routes with no ADR reference, external-service routes with no matching test subject, route metadata summaries over 200 characters (narrative content that belongs in an ADR instead), and routes sharing a `flow` that declare conflicting `domain` or `risk` values.
- `necromancer:generate` renders conditional `Domain`, `Risk`, `External Services`, and `ADR` columns in the routes table, following the existing `Authorization`/`Parameters`/`Source` conditional-column pattern.
- `necromancer:diff` renders a deterministic "Flagged Routes" section (text and markdown) for any added or changed route tagged high/critical risk or declaring external services — no AI review required. Each flagged route also shows its `domain`, `flow`, and `capability` when declared, alongside the risk/external-service reason it was flagged.
- `necromancer:diff --review`'s AI prompt now includes the same "Flagged Routes" signal, so the LLM's risk assessment is grounded in declared route metadata rather than inferred purely from the raw diff. The shared flagging logic lives in `LaravelNecromancer\Diff\FlaggedRoutes`, used by both the deterministic and AI-review paths so they can never disagree about which routes are flagged.
- `necromancer:ask` now prepends a "Most Relevant Evidence" section (ranked via `PromptRelevanceScorer`, the same scorer `necromancer:prompt` uses) ahead of the full manifest, prioritizing the AI's attention without discarding anything. `PromptRelevanceScorer` weighs a route's declared `domain`/`flow`/`capability` as strongly as `class`/`name`, and `summary` like `description` — declared metadata outranks inferred/observed fields for relevance, matching the priority rule used elsewhere.

## 1.1.1

### Added

- `exclude.route_uris` config key for excluding routes by URI pattern (via `Str::is`). Unlike `exclude.routes`, which only matches route names, this also catches unnamed routes such as Laravel's `/up` health-check endpoint — which is now excluded by default, so it no longer appears as an unnamed-route audit finding.
- `appends` field on the model artifact, capturing accessors appended to model serialization. Collected via `getAppends()`, so both the `$appends` property and the `#[Appends]` attribute are picked up. Rendered as an `appends: …` entry in the generated model table.

### Fixed

- `MissingFillableCheck` no longer flags models that use the guarded strategy (`$guarded` / `#[Guarded]`). A model with `guarded` set to anything other than the Eloquent default `['*']` has a defined mass-assignment surface even with an empty `fillable`.
- `ModelsWithOpenGuardCheck` no longer flags an open guard (`$guarded = []`) when a non-empty `fillable` whitelist constrains mass assignment — eliminating a false positive on `Pivot` models, which default to `$guarded = []`.

## 1.1.0

### Added

- `necromancer:generate --paths=...` to filter the generated context by source file path prefix. Matches each artifact's `source.file` (falling back to the top-level `file` for tests), is combinable with `--only`/`--except`, omits empty sections, and warns on path prefixes that match nothing.
