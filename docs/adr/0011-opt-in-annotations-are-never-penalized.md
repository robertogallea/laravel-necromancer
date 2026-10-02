# Artifact Annotations are opt-in and never lower a score that would otherwise be clean

An application that declares no Artifact Annotations behaves as if the feature didn't exist. `necromancer:doctor`'s Artifact Annotation Coverage dimension scores N/A until at least one artifact declares an annotation. The annotation audit checks (high-risk without ADR, external services without tests, narrative summaries, inconsistent flows, identifier style, missing local ADR files) only look at artifacts that have declared something. Only partial or inconsistent adoption is scored. Annotations are a voluntary statement of intent: penalising an application for not using them would turn a readability tool into an adoption campaign and make every pre-existing app's score drop on upgrade.

The same rule applies to the scoring model in general. A doctor dimension with no applicable artifacts scores N/A, and an audit check with nothing to check is skipped. An app is never marked down for lacking something it has no reason to have.

## Consequences

- A doctor dimension that scores N/A currently counts as 100% at its full weight in the overall score (`DoctorAnalyzer::overallScore()`), so it can't lower the score but can raise it. Excluding N/A dimensions from the weighted average would be a separate, deliberate change.
- A new annotation-based check or dimension must stay silent for applications that haven't declared the annotation it reads.
