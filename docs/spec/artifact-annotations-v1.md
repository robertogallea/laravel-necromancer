# Artifact Annotations v1

Status: Accepted  
Target release: 1.5.0  
Depends on: [ADR 0001](../adr/0001-use-universal-artifact-annotations.md)  
Superseded by: none

## 1. Purpose

This specification defines the first public contract for canonical Artifact IDs and developer-declared annotations across every artifact collected by Laravel Necromancer. It is the implementation contract for release 1.5.0.

The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**, and **MAY** are normative.

## 2. Scope

Version 1 covers:

- canonical Artifact IDs for every current artifact type;
- a closed, versioned annotation schema;
- the public `#[Necromancer]` attribute and `Risk` enum;
- exact-ID configuration mappings;
- controller class and action annotations applied to routes;
- native Laravel route metadata as a declaration source;
- deterministic resolution, merging, validation, and diagnostics;
- manifest schema and content-hash changes;
- reading unversioned manifests throughout the 1.x release line;
- migration of search, diff, audit, Doctor, generated documentation, and MCP consumers.

Version 1 does not cover:

- OKF generation or AI enrichment;
- arbitrary annotation keys;
- wildcard configuration mappings;
- user-assigned Artifact IDs;
- universal PHPDoc parsing;
- annotation inheritance between PHP parent classes or traits;
- inferred Domain, Flow, Capability, or Risk values;
- attributes on closures, scheduled tasks, gates, or Pest functions.

## 3. Domain language

The terms Artifact Annotation, Discovered Fact, Domain, Flow, Capability, Risk, Artifact Metadata, and Artifact ID have the meanings in [`CONTEXT.md`](../../CONTEXT.md). Implementations and user-facing documentation MUST use those terms consistently.

An attribute is one declaration mechanism for an Artifact Annotation. The words "attribute" and "annotation" are not interchangeable.

## 4. Public PHP API

### 4.1 Risk enum

Add `LaravelNecromancer\Metadata\Risk` as a string-backed enum:

```php
<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

enum Risk: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
```

`Risk` is the authoritative set of values for Annotation Schema v1.

### 4.2 Necromancer attribute

Add `LaravelNecromancer\Attributes\Necromancer` with this public signature:

```php
<?php

declare(strict_types=1);

namespace LaravelNecromancer\Attributes;

use Attribute;
use LaravelNecromancer\Metadata\Risk;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Necromancer
{
    /**
     * @param list<string> $externalServices
     * @param list<string> $adrs
     */
    public function __construct(
        public ?string $domain = null,
        public ?string $flow = null,
        public ?string $capability = null,
        public ?string $summary = null,
        public ?Risk $risk = null,
        public array $externalServices = [],
        public array $adrs = [],
    ) {}
}
```

AN-API-001: The attribute MUST NOT be repeatable.

AN-API-002: Attribute arguments MUST remain optional so an application can adopt individual fields incrementally.

AN-API-003: Future schema fields MAY be appended as optional named parameters in a minor release. Existing parameters MUST NOT be reordered or renamed during 1.x.

### 4.3 Route API

Every existing `withNecromancer()` macro and `RouteMetadataFactory::forMetadata()` MUST retain its current named parameters. The `risk` parameter MUST widen from `?string` to `Risk|string|null`, and a plural `adrs` parameter MUST be appended after the legacy singular `adr` parameter:

```php
?string $domain = null,
?string $flow = null,
?string $capability = null,
?string $summary = null,
Risk|string|null $risk = null,
string|array|null $externalServices = null,
?string $adr = null,
array $adrs = [],
```

AN-API-004: Passing a `Risk` enum MUST write its string value into native route metadata.

AN-API-005: The singular `adr` and plural `adrs` inputs MUST be merged, deduplicated, and exposed canonically as `annotations.adrs`.

AN-API-006: The route factory MUST preserve the caller's legacy `adr` and plural `adrs` keys in raw native route metadata. It MUST NOT rewrite unrelated native metadata.

AN-API-007: When both ADR forms are supplied, the singular value precedes plural values before deduplication.

## 5. Annotation Schema v1

The serialized schema is:

```json
{
  "domain": "billing",
  "flow": "subscription-cancellation",
  "capability": "subscription.cancel",
  "summary": "Cancels an active subscription.",
  "risk": "high",
  "external_services": ["stripe"],
  "adrs": ["docs/adr/004-subscription-cancellation.md"]
}
```

All fields are optional. At least one non-empty field is required for the `annotations` object to be serialized.

| Field | Serialized type | Normalization | Quality guidance |
|---|---|---|---|
| `domain` | non-empty string | trim outer whitespace | lowercase kebab case |
| `flow` | non-empty string | trim outer whitespace | lowercase kebab case |
| `capability` | non-empty string | trim outer whitespace | lowercase dotted form |
| `summary` | non-empty string | trim outer whitespace | one sentence, at most 200 bytes |
| `risk` | enum string | enum value | one of four cases |
| `external_services` | list of non-empty strings | trim, exact dedupe | lowercase stable identifiers |
| `adrs` | list of non-empty strings | trim, exact dedupe | repository path or absolute URI |

AN-SCHEMA-001: Serialized keys MUST appear in the table order above. This ordering is required for deterministic fixtures even though JSON object order is not semantically significant.

AN-SCHEMA-002: Null attribute parameters and empty lists mean "not declared" and MUST be omitted.

AN-SCHEMA-003: An explicitly supplied empty or whitespace-only string is invalid. The resolver MUST NOT silently convert it into an absent value.

AN-SCHEMA-004: Every list item MUST be a string that remains non-empty after trimming. Invalid item types and empty items are schema errors.

AN-SCHEMA-005: Deduplication is exact and case-sensitive. The first occurrence determines list order.

AN-SCHEMA-006: Identifier style is audit guidance during 1.x. The resolver MUST preserve case and MUST NOT rewrite Domain, Flow, Capability, external-service, or ADR values.

AN-SCHEMA-007: Unknown keys in configuration mappings are schema errors.

AN-SCHEMA-008: A summary longer than 200 bytes, noncanonical identifier spelling, a missing ADR file, or inconsistent Flow declarations are quality findings rather than schema errors.

## 6. Canonical Artifact IDs

Artifact IDs are opaque, case-sensitive strings. Consumers MUST compare the full string and MUST NOT parse IDs to recover artifact fields.

AN-ID-001: Every serialized artifact MUST contain exactly one top-level `id`.

AN-ID-002: IDs MUST be derived; applications cannot override them in Annotation Schema v1.

AN-ID-003: A scan MUST fail when two serialized artifacts produce the same ID.

AN-ID-004: FQCN inputs MUST omit a leading namespace separator. Repository paths MUST use `/` separators.

AN-ID-005: Identity components retain their declared case. Type prefixes use the manifest's snake-case artifact key.

### 6.1 Identity table

| Artifact type | Canonical ID |
|---|---|
| `routes` | `routes:{METHODS}:{URI}` |
| `models` | `models:{FQCN}` |
| `form_requests` | `form_requests:{FQCN}` |
| `jobs` | `jobs:{FQCN}` |
| `events` | `events:{FQCN}` |
| `listeners` | `listeners:{FQCN}` |
| `commands` | `commands:{FQCN}` |
| `policies` | `policies:{FQCN}` |
| `enums` | `enums:{FQCN}` |
| `observers` | `observers:{FQCN}` |
| `livewire_components` | `livewire_components:{FQCN}` |
| `mailables` | `mailables:{FQCN}` |
| `validation_rules` | `validation_rules:{FQCN}` |
| `service_providers` | `service_providers:{FQCN}` |
| `tests` | `tests:{REPOSITORY_RELATIVE_FILE}` |
| gate ability | `gates:ability:{ABILITY}` |
| gate before hook | `gates:before_hook:{ZERO_BASED_INDEX}` |
| gate after hook | `gates:after_hook:{ZERO_BASED_INDEX}` |
| global middleware | `middleware:global:{FQCN}` |
| grouped middleware | `middleware:group:{GROUP}:{FQCN}` |
| middleware alias | `middleware:alias:{ALIAS}` |
| scheduled task | `scheduled_tasks:{SHA256}:{ONE_BASED_OCCURRENCE}` |

### 6.2 Type-specific rules

AN-ID-006: Route methods MUST be uppercased, `HEAD` MUST be removed when `GET` is present, duplicates MUST be removed, and remaining methods MUST be sorted lexicographically before joining with `|`. The URI component is the serialized Laravel URI and is not inferred from the route name.

AN-ID-007: A route-name change MUST NOT change a Route Artifact ID. A method-set or URI change MUST change it.

AN-ID-008: Class-backed artifacts use the FQCN even when another field looks user-facing. A command signature, model table, Livewire view, or policy model change MUST be represented as an artifact change rather than a remove/add pair.

AN-ID-009: A test uses its normalized repository-relative file path. A declared PHPUnit class or inferred subject change MUST NOT change its ID.

AN-ID-010: Middleware IDs represent registrations. A middleware class registered globally, in two groups, and under an alias produces four artifacts with four IDs. Changing the class behind an alias is an artifact change under the same alias ID.

AN-ID-011: Gate hook indexes follow Laravel's runtime registration order independently for before and after hooks. Reordering hooks changes their IDs. Named gate abilities are independent of hook indexes.

AN-ID-012: The scheduled-task digest is lowercase SHA-256 of canonical JSON containing, in this order: `command`, `expression`, `without_overlapping`, `run_in_background`, `even_in_maintenance`, `timezone`, and `description`. `human_readable` and `source` are excluded because they are derived or currently unavailable.

AN-ID-013: Identical scheduled-task tuples receive a one-based occurrence number in Laravel registration order. Changing any tuple field is represented as remove/add. This limitation MUST be documented because exact-ID mappings for scheduled tasks move when their schedule semantics change.

AN-ID-014: `ArtifactId` or an equivalent single service MUST own every rule in this section. `Inventory`, `ManifestDiffer`, `ScanCommand`, collectors, and downstream tools MUST NOT maintain private canonical-key implementations.

## 7. Supported declaration sources

| Artifact family | Class attribute | Method attribute | Framework native | Exact-ID mapping |
|---|---:|---:|---:|---:|
| Routes | controller default | controller action | Laravel route metadata | yes |
| Class-backed artifacts | direct | no | no | yes |
| Middleware registrations | shared class declaration | no | no | yes |
| PHPUnit/Pest test files | no | no | no | yes |
| Gates and gate hooks | no | no | no | yes |
| Scheduled tasks | no | no | no | yes |

The class-backed family is: models, form requests, jobs, events, listeners, commands, policies, enums, observers, Livewire components, mailables, validation rules, and service providers.

AN-SOURCE-001: PHP attributes are read only from application classes already accepted by the corresponding collector. An annotation MUST NOT cause an otherwise excluded artifact to enter the manifest.

AN-SOURCE-002: Parent-class and trait annotations are not inherited. Only the reflected class's own attribute is read.

AN-SOURCE-003: A middleware class annotation applies to every collected registration of that class. An exact-ID mapping can add registration-specific values.

AN-SOURCE-004: Test collection MUST remain parse-only and MUST NOT load test files merely to read attributes. Tests use exact-ID mappings in v1.

AN-SOURCE-005: Gates and scheduled tasks use exact-ID mappings until their collectors expose stable reflected declaration targets.

AN-SOURCE-006: For routes, the controller class is an inherited default, the action method is a more-specific source, and the fully resolved Laravel route metadata is the direct artifact declaration.

AN-SOURCE-007: Invokable controllers use the `__invoke` method as the route action source.

AN-SOURCE-008: Closure routes have no class or method source. They use native route metadata and exact-ID mappings.

## 8. Configuration contract

Add this top-level key to `config/necromancer.php`:

```php
'annotations' => [
    // 'jobs:App\\Jobs\\SendInvoice' => [
    //     'domain' => 'billing',
    //     'capability' => 'invoice.send',
    //     'risk' => 'high',
    // ],
],
```

AN-CONFIG-001: Configuration keys MUST be exact canonical Artifact IDs. Pattern syntax and wildcard expansion are not supported.

AN-CONFIG-002: Configuration field names use serialized snake case: `external_services` and `adrs`.

AN-CONFIG-003: Configuration risk values are strings and MUST match a `Risk` enum value exactly.

AN-CONFIG-004: A mapping whose artifact type is inside the selected scan scope but whose ID is not collected MUST emit a warning and MUST NOT create an artifact. Mappings for artifact types outside a partial scan's scope are not evaluated and emit no warning.

AN-CONFIG-005: Configured null values mean absent. Empty strings, invalid lists, and unknown fields remain schema errors.

AN-CONFIG-006: Mapping keys MUST have a known artifact-type prefix and the canonical shape for that type. A malformed key is a schema error.

## 9. Resolution and merge algorithm

One `ArtifactAnnotationResolver` or equivalent service MUST own source conversion, merge behavior, validation, and diagnostics.

For class-backed artifacts, the class attribute is a direct declaration. For routes, resolve in this order:

1. controller class attribute as inherited defaults;
2. controller action attribute as a more-specific declaration;
3. normalized native route metadata as the direct route declaration;
4. exact-ID mapping as a fill-only escape hatch.

For middleware, resolve the class attribute first and then the exact registration-ID mapping. For tests, gates, and scheduled tasks, resolve only the exact-ID mapping.

### 9.1 Scalar fields

AN-MERGE-001: A more-specific declaration replaces a less-specific scalar. Class-to-method refinement is expected and emits no warning.

AN-MERGE-002: Native route metadata replaces a different controller-derived scalar and MUST emit warning `AN_SOURCE_CONFLICT`, naming the Artifact ID, field, winning source, and ignored source.

AN-MERGE-003: An exact-ID mapping fills an absent scalar. When it supplies a different value for an existing scalar, the existing value wins and `AN_SOURCE_CONFLICT` is emitted.

AN-MERGE-004: Two declarations at equal specificity that provide different non-empty scalar values are an `AnnotationConflict` error and fail scanning.

### 9.2 List fields

AN-MERGE-005: `external_services` and `adrs` are merged from least to most specific, followed by configuration values. Exact duplicates are removed after each append.

AN-MERGE-006: Lists are additive in v1. There is no clear, subtract, or replace operator.

### 9.3 Resolution result

AN-MERGE-007: Resolution returns an immutable `ArtifactAnnotations` value object plus zero or more structured diagnostics. Collectors MUST NOT receive or manipulate raw annotation arrays after resolution.

AN-MERGE-008: `ArtifactAnnotations::jsonSerialize()` MUST implement Annotation Schema v1 and omit absent fields.

AN-MERGE-009: An empty resolved object MUST cause the artifact's top-level `annotations` key to be omitted.

## 10. Validation and diagnostics

### 10.1 Fatal errors

These conditions fail `necromancer:scan` with exit code `1` and prevent manifest writes:

| Code | Condition |
|---|---|
| `AN_SCHEMA_UNKNOWN_FIELD` | mapping contains a key outside Schema v1 |
| `AN_SCHEMA_INVALID_VALUE` | empty scalar, invalid list item, or invalid risk |
| `AN_ID_DUPLICATE` | two artifacts resolve to the same ID |
| `AN_SOURCE_EQUAL_CONFLICT` | equal-specificity scalar values disagree |

AN-VALID-001: Fatal messages MUST include the Artifact ID when one is known, the field when applicable, and enough source context to correct the declaration.

AN-VALID-002: A failed scan MUST leave an existing manifest untouched.

### 10.2 Warnings

| Code | Condition |
|---|---|
| `AN_SOURCE_CONFLICT` | a defined precedence rule ignores a different lower-priority value |
| `AN_CONFIG_UNMATCHED` | exact-ID mapping has no artifact in scan scope |
| `AN_LEGACY_RISK` | legacy raw route risk is not a Schema v1 enum value |
| `AN_LEGACY_VALUE` | legacy raw route annotation value cannot enter Schema v1 unchanged |
| `AN_IDENTIFIER_STYLE` | identifier is valid but noncanonical or near-duplicate |

AN-VALID-003: Warnings MUST NOT block manifest writing.

AN-VALID-004: `necromancer:scan` MUST render warnings once after collection and before its success message. Library callers MAY consume the structured diagnostics without console output.

AN-VALID-005: Unknown legacy route risk remains in `route_metadata.raw` and the deprecated compatibility projection, is omitted from `annotations.risk`, and emits `AN_LEGACY_RISK`. Annotation attributes and configuration mappings never receive this exception; an invalid risk there is fatal.

AN-VALID-006: Native route metadata retains 1.x compatibility for values previously accepted by `RouteMetadataNormalizer`: scalar strings, integers, and floats may normalize to trimmed strings, and a scalar external service may normalize to a one-item list. A value that cannot enter Schema v1 remains in raw metadata, follows the legacy normalizer's behavior in the compatibility projection, is omitted from `annotations`, and emits `AN_LEGACY_VALUE`. New attributes and configuration mappings remain strict.

### 10.3 Audit findings

Summary length, identifier style, missing local ADR files, high risk without ADRs, external services without tests, and Flow inconsistency remain audit concerns. Schema resolution MUST NOT silently repair these declarations.

## 11. Manifest Schema v1

### 11.1 Metadata

New scans MUST serialize:

```json
{
  "meta": {
    "generated_at": "2026-08-07T12:00:00+02:00",
    "content_hash": "...",
    "manifest_schema_version": 1,
    "annotation_schema_version": 1,
    "scope": {
      "complete": true,
      "artifact_types": [
        "commands",
        "enums",
        "events",
        "form_requests",
        "gates",
        "jobs",
        "listeners",
        "livewire_components",
        "mailables",
        "middleware",
        "models",
        "observers",
        "policies",
        "routes",
        "scheduled_tasks",
        "service_providers",
        "tests",
        "validation_rules"
      ]
    }
  }
}
```

AN-MANIFEST-001: `manifest_schema_version` and `annotation_schema_version` are integers and MUST both equal `1` for this specification.

AN-MANIFEST-002: `scope.artifact_types` contains the sorted set of collectors requested for the scan. A full scan contains every supported type and sets `complete` to true. A scan using `--only` sets `complete` to false even if the caller happens to list every type manually.

AN-MANIFEST-003: Existing metadata fields (`necromancer_version`, Laravel/PHP/application facts) remain present.

### 11.2 Artifact shape

```json
{
  "id": "jobs:App\\Jobs\\SendInvoice",
  "class": "App\\Jobs\\SendInvoice",
  "queue": "invoices",
  "connection": "redis",
  "tries": 3,
  "timeout": 120,
  "annotations": {
    "domain": "billing",
    "capability": "invoice.send",
    "risk": "high",
    "external_services": ["stripe"],
    "adrs": ["docs/adr/004-invoice-delivery.md"]
  }
}
```

AN-MANIFEST-004: `id` MUST be the first serialized artifact key. Existing payload keys retain their relative order. `annotations`, when present, MUST be the final artifact key. This lets `StructuralArtifact` wrap an unchanged type-specific payload deterministically.

AN-MANIFEST-005: All artifacts, including unannotated artifacts, receive an ID.

AN-MANIFEST-006: Raw framework metadata is a Discovered Fact and MUST NOT be merged into `annotations` except through the reserved configured Necromancer namespace.

### 11.3 Route compatibility shape

Throughout 1.x, annotated routes serialize all three relevant views:

```json
{
  "id": "routes:POST:billing/cancel",
  "method": "POST",
  "uri": "billing/cancel",
  "route_metadata": {
    "raw": {
      "necromancer": {
        "domain": "billing",
        "risk": "high",
        "adr": "docs/adr/004-subscription-cancellation.md"
      }
    },
    "necromancer": {
      "domain": "billing",
      "risk": "high",
      "adr": "docs/adr/004-subscription-cancellation.md",
      "adrs": ["docs/adr/004-subscription-cancellation.md"]
    }
  },
  "annotations": {
    "domain": "billing",
    "risk": "high",
    "adrs": ["docs/adr/004-subscription-cancellation.md"]
  }
}
```

AN-MANIFEST-007: `route_metadata.raw` is permanent first-class framework information.

AN-MANIFEST-008: `route_metadata.necromancer` is a deprecated 1.x compatibility projection. It retains singular `adr` as the first canonical ADR and adds `adrs` for complete fidelity. It is removed in 2.0.

The compatibility projection also preserves legacy normalized values that cannot enter the closed v1 annotation schema. Such values never reach new internal consumers because those consumers read `annotations`.

AN-MANIFEST-009: New internal consumers MUST read `annotations`; they MUST NOT read the compatibility projection.

### 11.4 Content hash

AN-HASH-001: The content hash input is canonical JSON of this exact shape:

```json
{
  "manifest_schema_version": 1,
  "annotation_schema_version": 1,
  "scope": {},
  "artifacts": {}
}
```

AN-HASH-002: Hashing uses lowercase SHA-256 over `json_encode` with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES`. Artifact types and artifacts MUST already be sorted deterministically before hashing.

AN-HASH-003: `generated_at`, package/runtime versions, application name, URL, and environment remain outside the content hash.

AN-HASH-004: A change to an Artifact ID, annotation, discovered fact, schema version, or scope MUST change the content hash.

## 12. Legacy manifest adaptation

Unversioned manifests are Manifest Schema v0.

AN-COMPAT-001: `ManifestReader` MUST pass every decoded manifest through one compatibility adapter before returning it to a command.

AN-COMPAT-002: The adapter MUST operate in memory and MUST NOT rewrite the file.

AN-COMPAT-003: For every v0 artifact, derive and insert the canonical `id`.

AN-COMPAT-004: For a v0 route, promote `route_metadata.necromancer` into Annotation Schema v1, convert singular `adr` to `adrs`, retain native raw metadata, and apply legacy-risk behavior.

AN-COMPAT-005: Non-route v0 artifacts receive no inferred annotations.

AN-COMPAT-006: The adapted array MUST have current in-memory versions (`manifest_schema_version: 1`, `annotation_schema_version: 1`). The adapter records the original version in its structured read diagnostics rather than adding a private key to the manifest.

AN-COMPAT-007: The adapter remains available through the last 1.x release and is removed in 2.0.

AN-COMPAT-008: Diffing a v0 manifest against an equivalent v1 scan MUST not report every artifact as changed merely because IDs and promoted route annotations were added by the adapter.

AN-COMPAT-009: Because v0 cannot distinguish full and partial scans, its adapted scope is conservative: `complete` is false and `artifact_types` is the sorted set of artifact keys present in the file. A fresh full scan is required before a future OKF exporter treats it as complete.

## 13. Consumer migration

### 13.1 Shared identity

AN-CONSUMER-001: `Inventory` sorts artifacts by `id`.

AN-CONSUMER-002: `ManifestDiffer` and `ScanCommand --diff` index artifacts by `id` and share the same identity implementation used by collectors and the compatibility adapter.

AN-CONSUMER-003: MCP query and search tools return `id` and `annotations` unchanged. New MCP methods are not required in v1.

### 13.2 Search relevance

AN-SEARCH-001: `PromptRelevanceScorer` reads `annotations` on every artifact type.

AN-SEARCH-002: `domain`, `flow`, and `capability` retain weight `3`; `summary` retains weight `2`; `risk`, external services, and ADRs retain weight `1`.

AN-SEARCH-003: Raw route metadata remains searchable with weight `1`. Its reserved Necromancer namespace MUST NOT be scored a second time after annotations are scored.

### 13.3 Diff review

AN-DIFF-001: Deterministic flagging applies to every added or changed artifact with risk `high` or `critical`, or a non-empty external-service list.

AN-DIFF-002: Output identifies a flagged artifact by type, ID, and best available display label. Existing route output retains its method/URI presentation.

AN-DIFF-003: AI diff review receives the same generalized flagged-artifact set as deterministic output.

### 13.4 Audit

The four existing route-metadata checks become artifact-annotation checks while retaining equivalent route behavior:

AN-AUDIT-001: High/critical risk without a non-empty `adrs` list produces a warning for every artifact type.

AN-AUDIT-002: A summary longer than 200 bytes produces a suggestion for every artifact type.

AN-AUDIT-003: Flow consistency groups all annotated artifacts by exact, case-sensitive Flow identity and checks declared Domain and Risk values. It emits at most one finding per artifact per Flow.

AN-AUDIT-004: External-service test evidence uses `TestSubjectMatcher` against the artifact's class when present, the route controller for routes, and the test file subject for test artifacts. An artifact without a matchable subject remains applicable and produces a warning.

AN-AUDIT-005: Identifier-style findings detect noncanonical forms and case-insensitive near duplicates without rewriting values.

AN-AUDIT-006: Missing local ADR paths produce findings; absolute URIs are not checked for reachability.

### 13.5 Doctor

AN-DOCTOR-001: The dimension label becomes `Artifact Annotation Coverage` and retains weight `0.10`.

AN-DOCTOR-002: Throughout 1.x, the emitted dimension key remains `route-metadata-coverage` for compatibility. The command's dimension filter accepts both `route-metadata-coverage` and `artifact-annotation-coverage`.

AN-DOCTOR-003: The score uses all annotated artifacts and retains the current component ratios: Domain presence, high-risk ADR coverage, external-service test evidence, and Flow consistency.

AN-DOCTOR-004: An application whose only annotations are its existing route annotations MUST receive the same score after migration.

AN-DOCTOR-005: The canonical key changes to `artifact-annotation-coverage` and the old alias is removed in 2.0.

### 13.6 Generated documentation

AN-GENERATE-001: Generated artifact sections MUST render a compact Architectural Context value when any annotation exists.

AN-GENERATE-002: Existing route-specific metadata columns remain compatible during 1.x but source their normalized values from `annotations`.

AN-GENERATE-003: Extended architectural prose remains in referenced ADRs and generated context; the annotation summary is kept compact.

## 14. Implementation boundaries

The following names are recommended boundaries, not additional public API commitments:

| Responsibility | Suggested location |
|---|---|
| public attribute | `src/Attributes/Necromancer.php` |
| public enum | `src/Metadata/Risk.php` |
| immutable schema value | `src/Metadata/ArtifactAnnotations.php` |
| resolution and validation | `src/Metadata/ArtifactAnnotationResolver.php` |
| canonical identity | `src/Manifest/ArtifactId.php` |
| v0 adaptation | `src/Manifest/ManifestCompatibilityAdapter.php` |
| structured warnings | `src/Metadata/AnnotationDiagnostic.php` |

AN-ARCH-001: The annotation resolver and identity service MUST be registered once through `NecromancerServiceProvider` and reused by collectors.

AN-ARCH-002: `StructuralArtifact` MUST own the Artifact ID and resolved annotations alongside its type-specific payload. Type-specific payload classes remain responsible only for discovered facts.

AN-ARCH-003: Adding annotations MUST NOT require adding common annotation properties to all 18 payload constructors.

AN-ARCH-004: Collectors provide declaration context—reflection objects, native metadata, and identity inputs—but do not implement schema or merge rules.

AN-ARCH-005: The implementation MUST preserve scan filtering and exclusion behavior. Resolver work occurs only after a collector has accepted an artifact.

## 15. Acceptance suite

Each normative requirement MUST be covered directly or through a mapped scenario. At minimum, implement these tests:

| Test | Requirements |
|---|---|
| `RiskTest` | AN-API-004, AN-SCHEMA-004 |
| `NecromancerAttributeTest` | AN-API-001 through AN-API-003 |
| `RouteMetadataAnnotationApiTest` | AN-API-004 through AN-API-007, AN-MANIFEST-007 through AN-MANIFEST-009 |
| `ArtifactAnnotationsTest` | AN-SCHEMA-001 through AN-SCHEMA-008, AN-MERGE-008, AN-MERGE-009 |
| `ArtifactIdTest` | AN-ID-001 through AN-ID-014 |
| `ArtifactAnnotationResolverTest` | AN-MERGE-001 through AN-MERGE-007 |
| `AnnotationConfigurationTest` | AN-CONFIG-001 through AN-CONFIG-006 |
| `AnnotationValidationTest` | AN-VALID-001 through AN-VALID-006 |
| `ControllerRouteAnnotationTest` | AN-SOURCE-006 through AN-SOURCE-008 |
| `ClassBackedArtifactAnnotationTest` | AN-SOURCE-001, AN-SOURCE-002 |
| `MiddlewareAnnotationTest` | AN-ID-010, AN-SOURCE-003 |
| `NonReflectableArtifactAnnotationTest` | AN-SOURCE-004, AN-SOURCE-005 |
| `ManifestSchemaV1Test` | AN-MANIFEST-001 through AN-MANIFEST-009 |
| `ManifestContentHashV1Test` | AN-HASH-001 through AN-HASH-004 |
| `LegacyManifestCompatibilityTest` | AN-COMPAT-001 through AN-COMPAT-009 |
| `ManifestIdentityConsumerTest` | AN-CONSUMER-001 through AN-CONSUMER-003 |
| `ArtifactAnnotationSearchTest` | AN-SEARCH-001 through AN-SEARCH-003 |
| `FlaggedArtifactsTest` | AN-DIFF-001 through AN-DIFF-003 |
| `ArtifactAnnotationAuditTest` | AN-AUDIT-001 through AN-AUDIT-006 |
| `ArtifactAnnotationDoctorTest` | AN-DOCTOR-001 through AN-DOCTOR-005 |
| `ArtifactAnnotationGenerateTest` | AN-GENERATE-001 through AN-GENERATE-003 |
| `StructuralArtifactAnnotationTest` | AN-ARCH-001 through AN-ARCH-005 |
| existing complete test suite | regression coverage |

### 15.1 Required golden fixtures

Add checked-in fixtures for:

1. an unversioned route-only manifest;
2. its adapted in-memory v1 representation;
3. a complete v1 manifest containing every artifact type without annotations;
4. a complete v1 manifest with all annotation fields represented;
5. a partial v1 manifest;
6. deterministic content hashes for fixtures 3 through 5.

### 15.2 Required edge scenarios

The suite is incomplete until it demonstrates:

- route class defaults refined by an action and overridden by native route metadata;
- scalar configuration conflict warning and list configuration merging;
- unknown legacy risk preserved only in raw metadata;
- two middleware registrations of one class receiving distinct IDs;
- duplicate gate hooks receiving occurrence IDs;
- duplicate scheduled tuples receiving occurrence IDs;
- a command signature change retaining its ID;
- a route-name change retaining its ID;
- a method or URI route change changing its ID;
- a v0-to-v1 diff with no false all-artifact change;
- an existing manifest surviving a failed scan unchanged;
- existing route-only search, audit, Doctor, diff, and generation assertions retaining their behavior.

## 16. Delivery order and completion

Implement in this order:

1. Add schema value objects, public enum/attribute, canonical identity, and their unit tests.
2. Add manifest versions, scan scope, deterministic hash v1, and v0 adapter fixtures.
3. Add the central resolver, validation, diagnostics, and exact-ID configuration.
4. Integrate class-backed collectors, then middleware, routes, tests, gates, and scheduled tasks.
5. Migrate sorting, diffing, search, audits, Doctor, generated documentation, and MCP consumers.
6. Add deprecation documentation and upgrade examples.
7. Run formatting, static analysis, the full test suite, and Composer validation on PHP 8.3, 8.4, and 8.5.

Phase 1 is complete only when:

- every supported artifact serializes a unique canonical ID;
- every declaration source resolves according to this specification;
- invalid declarations cannot overwrite a valid existing manifest;
- old manifests remain readable without false diffs;
- all semantic consumers read the common annotation field;
- every requirement in the acceptance table is covered;
- existing unannotated applications retain their current behavior except for additive manifest fields and the documented one-time content-hash change.
