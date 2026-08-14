# Universal Artifact Annotations and Open Knowledge Format Export

Status: Ready for issue publication  
Target releases: 1.5.0, 1.6.0, and 1.7.0  
Decision records: [universal Artifact Annotations](../adr/0001-use-universal-artifact-annotations.md), [OKF projection](../adr/0002-project-manifests-into-okf-bundles.md)

## Problem Statement

Laravel Necromancer currently lets developers declare architectural intent only on Route Artifacts through Laravel route metadata. This leaves the rest of the application without a consistent way to express a Domain, Flow, Capability, Risk, external service, concise summary, or ADR reference. AI agents, reviewers, audits, and generated documentation therefore receive uneven context and cannot reliably relate the full application.

The manifest also needs to become a reliable source for a portable Knowledge Bundle. It must retain Laravel-specific Discovered Facts while allowing a deterministic projection into Open Knowledge Format (OKF) documents, without treating generated or AI-enriched output as the source of truth.

## Solution

Introduce a closed, versioned Artifact Annotation schema for every Structural Artifact. An Artifact Annotation records explicit developer intent; it is distinct from a Discovered Fact. Give every artifact a deterministic Artifact ID, resolve annotations from a layered set of declaration sources, and serialize the resolved result in the canonical manifest.

Use that manifest as the sole input to a deterministic, version-pinned OKF exporter. The exporter produces one Artifact Concept per artifact and synthesized Domain and Flow Concepts, preserving provenance and links. A later, separate opt-in enrichment stage may improve prose but cannot alter facts, annotations, identifiers, or relationships.

## User Stories

1. As an application maintainer, I want to annotate every supported artifact with a Domain, so that AI systems understand the stable business area to which it belongs.
2. As an application maintainer, I want to annotate artifacts with a Flow, so that a cross-artifact business process is visible.
3. As an application maintainer, I want to annotate artifacts with a Capability, so that the application behavior they support is explicit.
4. As an application maintainer, I want to declare Risk as sensitivity rather than defect probability, so that reviews focus on the harm of incorrect behavior or change.
5. As an application maintainer, I want to record external services and ADR references, so that important dependencies and decisions travel with an artifact.
6. As an application maintainer, I want annotation fields to be optional, so that I can adopt the feature incrementally.
7. As an application maintainer, I want a closed annotation schema, so that typoed or arbitrary metadata does not silently become architecture knowledge.
8. As an application maintainer, I want typed PHP attributes where reflection is natural, so that intent is close to class and controller-action code.
9. As a route author, I want to keep using native Laravel route metadata, so that Necromancer remains aligned with framework behavior.
10. As a route author, I want existing route metadata calls and the legacy singular ADR input to remain compatible throughout 1.x, so that an upgrade does not require a flag day.
11. As an application maintainer, I want an exact Artifact ID configuration mapping, so that I can annotate closures, test files, gates, scheduled tasks, and vendor-owned code without artificial attributes.
12. As an application maintainer, I want exact mappings rather than wildcard rules, so that a declaration is precise and remains auditable.
13. As a controller author, I want a class annotation to supply defaults and an action annotation to refine them, so that common intent is not repeated while endpoint-specific intent remains clear.
14. As a route author, I want direct native route metadata to be the most specific declaration, so that the route definition can express endpoint intent.
15. As an application maintainer, I want list-valued annotations to combine predictably and scalar conflicts to fail visibly, so that I do not receive silently ambiguous architecture knowledge.
16. As an application maintainer, I want invalid annotation values to stop scanning and quality concerns to be reported separately, so that correctness and guidance are not confused.
17. As a user of the manifest, I want each artifact to have one deterministic Artifact ID, so that I can identify it across scans, tools, and generated knowledge.
18. As a user of the manifest, I want route IDs to survive route-name changes, so that cosmetic naming does not look like an artifact replacement.
19. As a user of scheduled-task annotations, I want IDs derived from canonical schedule semantics and registration order, so that even non-class artifacts can be mapped exactly.
20. As an AI agent, I want resolved annotations separate from Discovered Facts, so that explicit intent has clear provenance and is not mistaken for observation.
21. As an AI agent, I want raw framework route metadata retained, so that framework-specific facts are not discarded during normalization.
22. As an application maintainer, I want old manifests adapted during 1.x, so that existing stored manifests, diffs, and integrations remain readable without a bulk rewrite.
23. As an application maintainer, I want manifest diffs to compare canonical IDs and resolved annotations, so that meaningful annotation changes are reviewable.
24. As an application maintainer, I want search, audit, Doctor, documentation generation, and MCP queries to use the universal annotation model, so that each product surface gives consistent answers.
25. As an application maintainer, I want existing route-only coverage keys preserved during 1.x, so that automation consuming current Doctor output remains compatible.
26. As a documentation consumer, I want the manifest to remain the canonical application representation, so that exports never become a competing source of truth.
27. As an application maintainer, I want a deterministic OKF bundle generated from a manifest without a new application scan, so that output is reproducible and can be reviewed independently.
28. As a knowledge consumer, I want one Artifact Concept per artifact, so that every unit of application knowledge has a stable, focused document.
29. As a knowledge consumer, I want synthesized Domain and Flow Concepts, so that related artifacts can be explored as business architecture.
30. As an AI agent, I want essential context in both structured fields and concise document prose, so that it remains understandable when a consumer strips front matter.
31. As a knowledge consumer, I want deterministic links for manifest relationships and clear plain text for unresolved targets, so that bundle navigation is trustworthy.
32. As an application maintainer, I want locally declared ADRs copied with provenance and missing local ADRs reported as errors, so that decision references are portable and valid.
33. As an application maintainer, I want raw route metadata rendered as framework metadata rather than promoted into portable OKF fields, so that framework detail retains its meaning.
34. As an application maintainer, I want bundle timestamps derived from the manifest, so that identical input generates identical output.
35. As an application maintainer, I want stale or partial manifests rejected by default, so that the bundle does not overstate application coverage.
36. As an application maintainer, I want explicit override flags for stale and partial input, so that exceptional workflows remain possible and visible.
37. As an application maintainer, I want generated bundles replaced atomically only in owned output locations, so that a failed export cannot damage user content.
38. As an application maintainer, I want optional AI enrichment in a separate bundle, so that deterministic output stays reproducible.
39. As a security-conscious maintainer, I want enrichment input to exclude raw framework metadata, source paths and hashes, configuration, and ADR bodies, so that optional AI use has a bounded privacy surface.
40. As a knowledge consumer, I want enrichment provenance and cache identity recorded, so that I can distinguish generated prose from deterministic knowledge.
41. As an application maintainer, I want legacy compatibility removed only in 2.0, so that the 1.x adoption path is predictable.

## Implementation Decisions

- The canonical model distinguishes Discovered Facts from Artifact Annotations. Artifact Metadata comprises both; annotations do not override discovered structural facts.
- The common, closed Annotation Schema v1 has optional `domain`, `flow`, `capability`, `summary`, `risk`, `external_services`, and `adrs` fields. Unknown configuration keys and malformed values are errors. Identifier-style guidance, missing ADR files, excessive summary length, and inconsistent Flow declarations are quality findings.
- Risk has four values: `low`, `medium`, `high`, and `critical`. It describes potential harm from incorrect behavior, misuse, or an incorrect change.
- The public PHP declaration is one non-repeatable `Necromancer` attribute for classes and methods, with an enum-backed Risk argument. Existing route APIs retain their named parameters, accept the enum or string Risk, and append plural ADR support while retaining legacy singular ADR support.
- Declaration sources are layered: framework-native route metadata; the typed attribute; and exact Artifact ID configuration mappings. Existing docblock prose remains a discovered fact or fallback and is not a custom annotation authoring channel.
- Controller class annotations supply route defaults; controller action annotations refine them; native route metadata is the direct declaration. Configuration fills omitted values only. More-specific scalar conflicts are diagnosed; lists are merged with exact deduplication in declaration order.
- Attributes apply directly to accepted class-backed artifacts and to middleware classes. Test files, gates, gate hooks, scheduled tasks, and closures use exact-ID mappings in v1. No parent-class or trait inheritance is introduced.
- Every serialized Structural Artifact has an opaque, case-sensitive, deterministic Artifact ID derived from its type and natural identity. One centralized identity service owns ID construction for collectors, scan output, diffs, and consumers. Applications cannot override IDs.
- Route IDs use normalized method sets and URI; class-backed IDs use FQCN; test IDs use normalized repository-relative file paths; gate and middleware IDs identify registrations; scheduled-task IDs hash a canonical semantic tuple plus registration occurrence.
- The manifest becomes schema-versioned and adds top-level artifact `id` and resolved `annotations`. It continues to preserve raw route metadata permanently. The normalized legacy route metadata namespace remains as a compatibility adapter for all of 1.x and is removed in 2.0.
- Old manifests remain readable in 1.x through a conservative adapter that derives IDs and promotes compatible legacy route declarations. Partial manifest scope is explicit and must not be presented as complete coverage.
- Search relevance, diff rendering, audit checks, Doctor dimensions, generated documentation, and MCP artifact queries consume resolved annotations generically. The existing route-only Doctor identifier remains available during 1.x while a universal Annotation Coverage view is added.
- Release 1.5.0 delivers universal annotations and manifest migration. Release 1.6.0 delivers deterministic OKF 0.2 projection. Release 1.7.0 adds optional AI enrichment. Release 2.0 removes legacy route-only compatibility fields and aliases.
- The OKF exporter consumes an existing manifest only and emits a version-pinned OKF 0.2 Knowledge Bundle. It creates Artifact Concepts plus synthesized Domain Concepts and Flow Concepts. The manifest remains canonical.
- Each concept uses standard OKF fields for portable meaning and a namespaced Necromancer field set for artifact identity, schema version, provenance, and application-specific detail. Front matter is authoritative; a concise Architectural Context section mirrors essential information for less structured consumers.
- Output file names combine a readable slug with a short Artifact ID hash; identity remains authoritative in structured content. Standard Markdown links represent resolvable manifest relationships. Missing manifest targets are rendered as clear text rather than invented links.
- Declared local ADR references are copied into the bundle as concepts with provenance; external URIs remain links. A missing local ADR declaration is an export error.
- The exporter records deterministic source provenance and uses the manifest generation time, never the export clock. Repository URLs are emitted only from explicit configuration, never inferred from Git remotes.
- The exporter validates both OKF requirements and stricter Necromancer integrity rules. It rejects known-stale or partial input by default, supports explicit allow flags, and writes through an owned temporary directory before atomic replacement.
- Optional enrichment writes a sibling bundle and can alter prose only. It excludes raw framework metadata, source paths and hashes, configuration, and ADR bodies from prompts; it records provider, model, prompt version, privacy policy, and cache provenance.

## Testing Decisions

- The primary acceptance seam is the existing scan command and its serialized manifest. A good feature test declares annotations through supported public inputs, runs the command, and asserts observable manifest content, diagnostics, and downstream command behavior rather than resolver internals.
- Existing scan-command feature tests are the primary prior art and should be extended with fixtures representing every collected artifact family, controller defaults and refinements, native route metadata, exact-ID mappings, compatibility reads, partial scans, conflicts, and invalid schema values.
- Deterministic ID construction and annotation normalization may have narrowly scoped unit tests because they are pure public-contract rules. These tests protect canonicalization boundaries; they should not duplicate feature tests.
- Existing feature tests for diff, audit, Doctor, generated documentation, and MCP-facing manifest behavior should assert universal annotations where their externally visible output changes. Legacy route metadata assertions stay during 1.x.
- The OKF feature seam is its public export command reading a previously generated manifest and producing a bundle. Tests should assert stable files, authoritative structured fields, prose fallback, links, ADR copying, scope/staleness refusal, override behavior, validation failure, and atomic-output safety.
- Determinism tests run the same manifest through the exporter twice and compare complete output. Enrichment tests use a fake provider and assert that it writes only a sibling bundle, honors the privacy boundary, records provenance, and cannot mutate facts, annotations, IDs, or links.

## Out of Scope

- Arbitrary annotation key-value data, wildcard configuration mappings, user-assigned Artifact IDs, and a general PHPDoc annotation language.
- Inference of Domain, Flow, Capability, or Risk from code or AI output.
- Attributes on closures, Pest functions, gates, or scheduled tasks in the initial annotation release.
- Parent-class or trait annotation inheritance.
- Making OKF documents authoritative, rescanning the application during export, or automatic discovery of Git repository URLs.
- AI enrichment in the deterministic exporter, enrichment of facts or relationships, and copying private source/configuration/ADR content to an AI provider.
- Removing legacy route-only manifest fields, public route API compatibility, or Doctor aliases before 2.0.

## Further Notes

The detailed 1.5.0 implementation contract is maintained separately as Artifact Annotations v1. The two accepted decision records establish the glossary and architectural boundaries for this work. The tracker issue should use the `ready-for-agent` label only; no additional triage is needed.

The agreed high-level test seam is `necromancer:scan`, with the future public OKF export command as the corresponding export seam. This preserves a small number of behavior-focused seams while still allowing pure deterministic rules to be tested directly.
