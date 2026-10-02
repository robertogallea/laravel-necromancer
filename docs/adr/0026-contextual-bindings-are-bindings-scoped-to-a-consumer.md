# Contextual bindings are Bindings scoped to a Consumer

`$this->app->when(ReportController::class)->needs(PaymentGateway::class)->give(FakePaymentGateway::class)` changes what one **Consumer** receives, so a manifest that records only the **Global Binding** of `PaymentGateway` tells an agent the wrong concrete for `ReportController`. Extending [0025](0025-bindings-are-read-from-the-booted-container.md), the scan reads the container's public `$contextual` map (`consumer → abstract → implementation`) and records each **Contextual Binding** as a `bindings` artifact with a `consumer` field and the ID `bindings:<abstract>@<consumer>` (`@` cannot appear in a class name, and the ID sorts right after the abstract's Global Binding). Nothing is executed: a class-string implementation is the concrete, a closure contributes its declared return type or `null`, as in 0025. Two Relationships link it: `consumed_by` (binding → Consumer) and `takes_precedence_over` (binding → the Global Binding of the same abstract, unresolved under the raw abstract when there is none), so following Relationships from a Global Binding always reaches the Contextual Bindings that override it.

## Considered Options

- **An `overrides` list on the Global Binding** — fails when the abstract is bound only contextually, which is common; it would need a synthetic Global Binding with no concrete. Rejected.
- **A new `contextual_bindings` artifact type** — same coverage, but a second type with an almost identical payload and duplicated generate/map/MCP surfaces. Rejected.
- **A fact on the Consumer artifact** — lost whenever the Consumer is not collected (a plain service class, a vendor class). Rejected.

## Consequences

- The class-to-binding fallback in Relationship resolution matches Global Bindings only, so a class end never resolves to a Contextual Binding and no existing Relationship changes target.
- A Contextual Binding counts as the application's when its Consumer, abstract, or concrete is in the application namespace. `exclude.bindings` still matches the abstract.
- A Global Binding omits `consumer` rather than recording `null`, so only applications that have Contextual Bindings report drift on the first scan after upgrading.
- A Contextual Binding has `lifetime: null` (the container resolves its concrete through that class's own binding), `provider: null`, and `deferred: false`.
- Primitive needs (`needs('$timeout')`) are skipped entirely: a plain `give()` stores the resolved value, which may be a configuration value [0003](0003-never-collect-config-values-or-read-env.md) forbids collecting. `giveTagged()`/`giveConfig()` on a class need are closures and record `concrete: null`; tags and config keys are not extracted.
- `#[Give]` and the other contextual parameter attributes are not read.
- From a changed concrete, a Consumer's tests are three Relationships away (concrete ← binding → Consumer → test), beyond `necromancer:affected-tests`' default depth of 2.
