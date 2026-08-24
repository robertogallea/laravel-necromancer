# Changelog

All notable changes to `laravel-necromancer` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 2.0.1

- remove non .php files from the staleness check
- exclude inertia and livewire uri routes

## 2.0.0

### Added

- `necromancer:graph` command, projecting the manifest into a deterministic Artifact Graph: `graph.json` (one node per collected artifact — id, kind, display label, resolved annotations, Discovered Facts — plus structural, grouping, and reference edges, all canonically ordered so an unchanged manifest produces byte-identical output) and a self-contained `graph.html` viewer (inline CSS/JS, no CDN dependencies) rendering a force-directed, kind-colored graph with edges styled distinctly per kind. Structural edges cover the same relationship taxonomy the OKF bundle renders (route→controller, model→relationships/policy/observers, event→listeners, listener→handles, policy→model, observer→model); grouping edges connect an artifact to its declared `domain`/`flow`; reference edges connect an artifact to a declared local `adrs` entry (absolute-URI ADRs are skipped, matching the OKF bundle's external-link treatment). The viewer is interactive: a sidebar doubles as a color legend and per-kind filter (hiding a kind hides its nodes and incident edges); a small edge key independently toggles structural/grouping/reference lines; clicking a node opens an inspect panel with its Architectural Context and Discovered Facts, or its member/referencing artifacts for a synthesized domain/flow/ADR node (hiding the selected node's kind closes the panel); scroll-to-zoom, drag-to-pan, and a Reset view button are also available. The graph data is embedded directly into `graph.html` at write time, so it opens straight from disk with no local server required (`graph.json` is still written alongside it as an independently useful artifact). Entirely independent of `necromancer:okf` (neither requires the other to have run); refuses a stale or partial-scope manifest by default (`--allow-stale`/`--allow-partial` override); writes atomically. Output defaults to `necromancer-graph/`, configurable via `--output=PATH` or the new `output.graph` config key.
- `necromancer:okf` writes a `README.md` alongside the bundle (purpose, structure, concept kinds, CLI/config reference, and a static mention of the `necromancer:okf-enrich` sibling), and `necromancer:okf-enrich` writes the analogous `okf-enriched/README.md`. Both bundles' `bundle.json` gain a `content_hash` field (the source manifest's `meta.content_hash`), without an `okf_version` bump.
- `necromancer:generate` announces a Knowledge Bundle's presence (deterministic and/or enriched) when one exists at its configured default path: a `## Knowledge Bundle` section in both Tier 1 (`CLAUDE.md`/`AGENTS.md`, or the Boost context path) and Tier 2 (`NECROMANCER.md`, or the Boost `SKILL.md`), naming its path, regenerate command, and live stats — exempt from `--only`/`--except`/`--paths`, suppressible via a new `okf.announce_in_context` config key. Each line compares that bundle's `content_hash` against the current manifest's and appends a "may be stale — re-run `<command>`" caveat on mismatch; a bundle with no `content_hash` key at all (pre-upgrade) renders with no staleness claim either way.

### Removed

- **BREAKING:** `ManifestReader` no longer adapts pre-1.5 ("v0"/unversioned) manifests in memory. `ManifestReader::read()` — used by every command that reads `necromancer.json` — now rejects any manifest whose `meta.manifest_schema_version` isn't `1`, treating it identically to a missing manifest — every such command shows the same "Necromancer manifest not found. Run necromancer:scan first." error. Run `php artisan necromancer:scan` once after upgrading to regenerate a current-schema manifest.
- **BREAKING:** `route_metadata.necromancer` is no longer written to the manifest. `route_metadata.raw` (untouched native `Route::getMetadata()` output) is unaffected. Resolved annotations for routes — like every other artifact family since 1.5.0 — are available only through the universal `annotations` key.
- **BREAKING:** `necromancer:doctor --only=route-metadata-coverage` no longer matches the Artifact Annotation Coverage dimension. Use the canonical `--only=artifact-annotation-coverage` key.
- **BREAKING:** The singular `adr` parameter was removed from `withNecromancer()` and `RouteMetadataFactory::forMetadata()`. Use the plural `adrs` array parameter instead (available since 1.5.0). The raw-array form (`->metadata(['necromancer' => ['adr' => '...']])`) is unaffected and still merges into `adrs`.

### Changed

- **BREAKING:** The `necromancer:scan` diagnostic codes `AN_LEGACY_VALUE` and `AN_LEGACY_RISK` are renamed to `AN_SCHEMA_INCOMPATIBLE_VALUE` and `AN_SCHEMA_INCOMPATIBLE_RISK`. The underlying check — a native `Route::metadata()` value that can't fit Annotation Schema v1 — is unchanged; only the code name changed, since the condition was never actually tied to manifest schema age.
- Internal: the relationship taxonomy behind the OKF bundle's `## Relationships` section (route→controller, model→relationships/policy/observers, event→listeners, listener→handles, policy→model, observer→model) is now exposed as structured data via `LaravelNecromancer\Relationships\RelationshipResolver`, extracted out of `ArtifactConceptBuilder`. No user-visible change — rendered bundle output is byte-identical.

### Fixed

- `necromancer:doctor`'s text output no longer misaligns the "Artifact Annotation Coverage" row. Its label is longer than the fixed 24-char column every other dimension pads into, so its progress bar started several columns late; it's now abbreviated to "Artifact Annotation Cov." for the text dashboard only, which fits the column and lines its bar up with the rest. `--json` output, `DimensionResult::$label`, and `--only=artifact-annotation-coverage` are unaffected — they still use the full "Artifact Annotation Coverage" name.
- `necromancer:doctor`'s text output no longer shifts a row's detail text when its score is 100% or a single digit. The percentage field is now right-padded to a fixed 3-digit width before the `%` sign, so `100%`, ` 82%`, and `  0%` all occupy the same 4 characters and every row's `(...)` detail starts in the same column.

See the [README's "Upgrading to 2.0" section](README.md#upgrading-to-20) for a full migration guide.

## 1.8.0

### Added

- `necromancer:okf` writes a `README.md` alongside the bundle (purpose, structure, concept kinds, CLI/config reference, and a static mention of the `necromancer:okf-enrich` sibling — never an existence check), and `necromancer:okf-enrich` writes the analogous `okf-enriched/README.md`. Both bundles' `bundle.json` gain a `content_hash` field (the source manifest's `meta.content_hash`), without an `okf_version` bump.
- `necromancer:generate` announces a Knowledge Bundle's presence (deterministic and/or enriched) when one exists at its configured default path: a `## Knowledge Bundle` section in both Tier 1 (`CLAUDE.md`/`AGENTS.md`, or the Boost context path) and Tier 2 (`NECROMANCER.md`, or the Boost `SKILL.md`), naming its path, regenerate command, and live stats — exempt from `--only`/`--except`/`--paths`, suppressible via a new `okf.announce_in_context` config key. Each line compares that bundle's `content_hash` against the current manifest's and appends a "may be stale — re-run `<command>`" caveat on mismatch; a bundle with no `content_hash` key at all (produced before this feature existed) renders with no staleness claim either way.

## 1.7.2

### Fixed

- `necromancer:generate`'s Boost skill output is now discoverable. It previously wrote `.ai/skills/necromancer.md` — a flat file Laravel Boost's `SkillComposer` silently ignores, since it only discovers directories under `.ai/skills/` containing their own `SKILL.md`. `boost:update` therefore never fanned the skill out to any AI agent (Claude Code, Codex, or any other Boost-supported agent). Necromancer now writes `.ai/skills/necromancer/SKILL.md` — with `name`/`description` frontmatter ahead of the existing "generated by" marker — which `boost:update` correctly distributes to each detected agent's own skill directory. A stale flat file from a previous version is deleted automatically, but only when it still carries Necromancer's own marker.
- `necromancer:benchmark`'s `necromancer`/`necromancer-mcp` conditions now read the skill content from the same new location, so benchmark runs aren't left silently reading nothing after the above fix.

## 1.7.0

### Added

- `necromancer:okf-enrich` command, generating an AI-enriched sibling OKF Knowledge Bundle without altering the deterministic one produced by `necromancer:okf`. Enrichment can only add a `description` field and an "AI-Enriched Summary" body section to a concept — it never calls a concept builder itself, so facts, annotations, Artifact IDs, and links cannot be mutated. Prompts are structurally incapable of including raw framework metadata, source paths/hashes, configuration, or ADR body content. Each concept caches independently, keyed by a hash of its own prompt plus provider/model/temperature/prompt version, and every enriched concept records provider, model, prompt version, privacy policy, and cache provenance. Options: `--provider=`, `--model=`, `--temperature=`, `--refresh`, `--output=`, `--allow-stale`, `--allow-partial`. Requires `laravel/ai`.
- `okf.enrichment` config block (`output`, `cache`, `provider`, `model`, `prompt_version`, `privacy_policy`).

## 1.6.0

### Added

- `necromancer:okf` command, projecting the manifest into a deterministic Open Knowledge Format (OKF) 0.2 Knowledge Bundle without rescanning the application: one Artifact Concept per collected artifact (authoritative YAML front matter keeping Discovered Facts and Artifact Annotations structurally distinct, plus a concise prose mirror), synthesized Domain and Flow Concepts grouping artifacts that share an annotation value, and ADR Concepts copied with provenance (a missing local ADR fails the export). Cross-artifact relationship fields (route controller, model relationships/policy/observers, event/listener pairings) render as Markdown links when resolvable in the bundle, plain text otherwise. Output is written atomically; refuses a stale or partial-scope manifest by default (`--allow-stale`/`--allow-partial` override).
- `okf.output` config key.

## 1.5.0

### Added

- Canonical, deterministic Artifact IDs for every collected artifact, and manifest schema versioning (`manifest_schema_version`, `annotation_schema_version`).
- The closed Annotation Schema v1 (`domain`, `flow`, `capability`, `summary`, `risk`, `external_services`, `adrs`) and the public, non-repeatable `#[Necromancer]` attribute for class-backed artifacts, controllers, and middleware — a controller class attribute supplies defaults, an action attribute refines them, and native `Route::metadata()` remains the most specific source, overriding a conflicting controller-derived value with an `AN_SOURCE_CONFLICT` warning.
- Exact-ID configuration mappings (`necromancer.annotations`) as the sole annotation source for closures, test files, gates, and scheduled tasks, and a fill-only escape hatch for registration-specific overrides on every other artifact family.
- `necromancer:audit`, `necromancer:doctor`, `necromancer:diff`, search relevance ranking, `necromancer:generate`, and MCP artifact queries now reason about resolved Artifact Annotations for every artifact family instead of routes only. New audit checks: `IdentifierStyleCheck`, `MissingLocalAdrFileCheck`. `HighRiskRoutesWithoutAdrCheck`, `ExternalServiceRoutesWithoutTestsCheck`, and `NarrativeRouteMetadataSummaryCheck` are renamed and generalized to `HighRiskArtifactsWithoutAdrCheck`, `ExternalServiceArtifactsWithoutTestsCheck`, and `NarrativeAnnotationSummaryCheck`.
- `necromancer:doctor`'s Route Metadata Coverage dimension becomes Artifact Annotation Coverage, scored over all annotated artifacts; its emitted key stays `route-metadata-coverage` for 1.x compatibility, and `--only` also accepts `artifact-annotation-coverage` as an alias.
- Invalid `#[Necromancer]` attribute or exact-ID mapping declarations are reported as controlled `necromancer:scan` failures (existing manifest left untouched) instead of an uncaught exception.

### Changed

- `necromancer:scan` reading an unversioned ("v0") manifest now adapts it in memory — assigning canonical IDs and promoting legacy route declarations into the universal annotation shape — rather than failing.

## 1.4.0

### Added

- `withNecromancer()` route macro for declaring route metadata, registered on all five routing surfaces — `Illuminate\Routing\Router` (`Route::withNecromancer(...)->group(...)`), `RouteRegistrar` (`Route::prefix(...)->withNecromancer(...)->group(...)`), `Route` (`Route::post(...)->withNecromancer(...)`), `PendingResourceRegistration` (`Route::resource(...)->withNecromancer(...)`), and `PendingSingletonResourceRegistration` (`Route::singleton(...)->withNecromancer(...)`). Takes `domain`, `flow`, `capability`, `summary`, `risk`, `externalServices` (string or array), and `adr` as optional named arguments; wraps them under the configured `route_metadata.namespace`, drops any argument left null, and hands the result to Laravel's native `->metadata()`. Group-level fields are inherited by the routes inside the group, with route-level fields winning per field (native Laravel metadata merging). Registered by `LaravelNecromancer\Routing\RouteMetadataMacros`, which is booted by the service provider.
- Because native route metadata only exists from Laravel 13.17, calling `withNecromancer()` on an older 13.x release throws a `RuntimeException` naming the required version rather than failing silently. Manifest collection is unchanged and still degrades quietly on those versions.

### Removed

- **BREAKING** — the `Necromancer` facade (`LaravelNecromancer\Facades\Necromancer`), its `Necromancer::forMetadata()` helper, and the `extra.laravel.aliases` root alias registered in `composer.json`. The macro replaces them: rewrite `->metadata(Necromancer::forMetadata(domain: 'billing'))` as `->withNecromancer(domain: 'billing')`. The raw `->metadata(['necromancer' => [...]])` array form is unaffected, as are the manifest shape, collection, normalization, and every downstream command. `LaravelNecromancer\Metadata\RouteMetadataFactory` still exists as the internal payload builder the macros delegate to.

## 1.3.1

### Fixed

- `Necromancer` facade root alias now registered via `extra.laravel.aliases` in `composer.json` (`"Necromancer": "LaravelNecromancer\\Facades\\Necromancer"`), matching how `laravel/head` registers its `Head` alias. Previously `Necromancer::forMetadata()` only worked with an explicit `use LaravelNecromancer\Facades\Necromancer;` import; it now resolves anywhere — including Tinker — with no import needed, via Laravel's package auto-discovery.

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
