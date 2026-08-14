# Laravel Necromancer

Laravel Necromancer represents Laravel applications in forms optimized for comprehension by AI systems.

## Language

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
A deterministic node/edge visualization of the manifest's artifacts and their structural, grouping (domain/flow), and reference (ADR) relationships.
_Avoid_: Concept Graph, dependency graph

**Bundle Announcement**:
A conditional notice inside a generated context file (CLAUDE.md, AGENTS.md, llms.txt) that a Knowledge Bundle exists on disk, naming its path, its regeneration command, and whether it may be stale relative to the current manifest.
_Avoid_: Bundle pointer, bundle reference
