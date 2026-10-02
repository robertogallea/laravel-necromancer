# Benchmark methodology: hand-written context is the baseline, the judge is a different model, and the static condition skips Q&A

`necromancer:benchmark` measures whether generated context helps an AI tool. Three choices in it look odd without context and protect the result from easy objections:

- **The hand-written context file (`AGENTS.md`) is the baseline that matters, not "no context".** "Does context help?" is trivially yes. The real question is whether generated, always-fresh structured context beats a file people write and maintain by hand.
- **The AI judge should be a different model from the one generating answers** (`benchmark.judge_model`/`judge_provider` are configured separately). A model judging its own output may favour its own style.
- **Q&A tasks don't run under the static `necromancer` condition.** Their answers are facts that the generated context file contains word for word, so that condition would score 100% by construction. Each bundled Q&A task lists the conditions it runs under (`none`, `manual`, `necromancer-mcp`, `necromancer-mcp-graph`). The MCP conditions keep them, because the model still has to pick the right tool and interpret what it returns.

To make the manifest less circular as a source of truth, golden answers resolved from it are cross-checked against the framework runtime where a check exists (`GoldenAnswerResolver::verifyAgainstRuntime()`: named routes against the router, model classes by `class_exists()`). Other fact keys are currently trusted as-is.

**The MCP graph tools get their own condition, `necromancer-mcp-graph`, instead of joining `necromancer-mcp`** (#70). `necromancer-mcp` keeps exactly its four query tools so its results stay comparable across versions. The new condition adds `get_artifact`, `get_relationships`, and `get_impact` on top of them, so the difference between the two conditions measures what following Relationships adds over flat queries. Both conditions share the same tool-calling step cap and run the same Q&A tasks, so the tools are the only variable. `get_affected_tests` is left out: no task asks which tests to run, and the benchmark model never edits files.

Golden answers for the Relationship-based tasks come from `RelationshipResolver`/`ImpactAnalyzer`. `events.listeners` is cross-checked against the event dispatcher at runtime. `dispatches.targets` (a source-text fact) and the generated impact task are trusted as-is: the runtime has nothing to check a dispatch against, and checking an Impact with a second traversal would be circular.

## Consequences

- The headline comparison is Necromancer against manual, not against no context. Adding a task or condition has to keep the bias protections above in place.
- A condition's tool set is frozen once results have been published. New tools go into a new condition.
- Aggregate scores are only comparable between runs with the same task set. Adding tasks to the bundled suite shifts every condition's averages, while each task's own results stay comparable through its task ID in the result dump.
