# Laravel Necromancer

Laravel Necromancer represents Laravel applications in forms optimized for comprehension by AI systems.

## Language

**Manifest**:
The machine-readable inventory of an application's artifacts that a scan produces and every other output is rendered from.
_Avoid_: Index, cache, context file

**Scan**:
The single inspection of a running application that produces the manifest.
_Avoid_: Parse, crawl

**Content Hash**:
A fingerprint of a manifest's artifact content that stays the same across scans of an unchanged application, regardless of when they ran.
_Avoid_: File checksum

**Drift**:
A difference between the committed manifest and what a fresh scan would produce.
_Avoid_: Staleness

**Stale Manifest**:
A manifest whose application source appears to have changed since it was scanned, which suggests drift without proving it.
_Avoid_: Drift, outdated

**Discovered Fact**:
Information about an artifact that Necromancer observes from the application rather than receiving as an explicit statement of intent.
_Avoid_: Inferred metadata, annotation

**Artifact Annotation**:
Architectural intent that a developer explicitly associates with an artifact.
_Avoid_: Discovered fact, attribute

**Domain**:
A stable business area or bounded context to which artifacts belong.
_Avoid_: Namespace, folder

**Flow**:
An end-to-end business process in which multiple artifacts participate.
_Avoid_: Request, call chain

**Capability**:
A stable behavior that the application provides.
_Avoid_: Permission, class responsibility

**Risk**:
The declared level of potential harm if an artifact behaves incorrectly, is misused, or is changed incorrectly. It expresses the artifact's sensitivity rather than the probability of a defect.
_Avoid_: Defect probability, change size

**Artifact Metadata**:
The information associated with an artifact, comprising both discovered facts and artifact annotations.
_Avoid_: Annotation, arbitrary data

**Artifact ID**:
A deterministic identifier that uniquely distinguishes an artifact by its type and natural identity.
_Avoid_: OKF filename, display name

**Action**:
A single-purpose application class that encapsulates one business operation behind one or more public entrypoint methods.
_Avoid_: Service, Livewire action, controller action

**Entrypoint**:
A public method through which an Action's business operation is invoked.
_Avoid_: Handler, action method

**Controller**:
An application class whose public methods handle HTTP requests routed to them.
_Avoid_: Handler, endpoint

**Controller Action**:
A public controller method that handles HTTP requests routed to it. Distinct from an **Action**, which is not tied to HTTP routing.
_Avoid_: Endpoint, route action, entrypoint

**Relationship**:
A directed, typed link from an artifact to another artifact, a Domain, a Flow, or an ADR, derived from the artifact's discovered facts and annotations rather than recorded separately. A relationship whose target is not a collected artifact is still a relationship, marked unresolved.
_Avoid_: Edge (a graph rendering of a relationship), dependency

**Dispatch**:
An artifact handing a job, an event, or a mailable to Laravel's bus, event dispatcher, or mailer for handling. Firing an event and sending a mailable are dispatches too.
_Avoid_: Fire, emit, send, trigger

**Provenance**:
How Necromancer obtained the evidence for a relationship: from the application's runtime state, by reflecting on declared code structure, by reading source text, or from an artifact annotation. A relationship supported by several pieces of evidence carries each of their provenances.
_Avoid_: Confidence, origin

**Impact**:
The artifacts, Domains, Flows, and ADRs reachable from a starting artifact by following relationships in either direction, each at its shortest distance from the start.
_Avoid_: Blast radius, dependents

**Boundary Node**:
A Domain, Flow, ADR, middleware, or test reached while computing an Impact: it belongs to the Impact, but the relationships beyond it don't.
_Avoid_: Hub, leaf

**Knowledge Bundle**:
A portable collection of interlinked knowledge documents generated to make an application's architecture understandable to people and AI systems.
_Avoid_: OKF package, metadata dump

**Artifact Concept**:
A unit of knowledge describing one application artifact.

**Domain Concept**:
A unit of knowledge describing a domain and connecting the artifacts associated with it.

**Flow Concept**:
A unit of knowledge describing a flow and connecting the artifacts that participate in it.

**Artifact Graph**:
A deterministic node/edge visualization of the manifest's artifacts and their structural, grouping (domain/flow), reference (ADR), and behavioral (dispatch) relationships.
_Avoid_: Concept Graph, dependency graph

**Bundle Announcement**:
A conditional notice inside a generated context file (CLAUDE.md, AGENTS.md, llms.txt) that a Knowledge Bundle exists on disk, naming its path, its regeneration command, and whether it may be stale relative to the current manifest.
_Avoid_: Bundle pointer, bundle reference
