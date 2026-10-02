# Benchmark methodology: hand-written context is the baseline, the judge is a different model, and the static condition skips Q&A

`necromancer:benchmark` measures whether generated context helps an AI tool. Three choices in it look odd without context and protect the result from easy objections:

- **The hand-written context file (`AGENTS.md`) is the baseline that matters, not "no context".** "Does context help?" is trivially yes. The real question is whether generated, always-fresh structured context beats a file people write and maintain by hand.
- **The AI judge should be a different model from the one generating answers** (`benchmark.judge_model`/`judge_provider` are configured separately). A model judging its own output may favour its own style.
- **Q&A tasks don't run under the static `necromancer` condition.** Their answers are facts that the generated context file contains word for word, so that condition would score 100% by construction. Each bundled Q&A task lists the conditions it runs under (`none`, `manual`, `necromancer-mcp`). `necromancer-mcp` keeps them, because the model still has to pick the right tool and interpret what it returns.

Golden answers are resolved from the manifest and then checked against the framework runtime, so a task can't pass just because the manifest agrees with itself.

## Consequences

- The headline comparison is Necromancer against manual, not against no context. Adding a task or condition has to keep the bias protections above in place.
