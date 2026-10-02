# Controller middleware is read without instantiating the controller

For each Controller Action, `ControllerCollector` records the middleware the controller itself declares. It resolves it the way Laravel's `Route::controllerMiddleware()` does: static `HasMiddleware::middleware()` entries first, then `#[Middleware]` attributes (parent classes first, then the method), minus `#[WithoutMiddleware]`, honouring `only`/`except`. But it never constructs the controller. Constructing a controller resolves its dependencies and runs its constructor, which is application code with side effects that a read-only scan must not trigger ([0002](0002-runtime-introspection-over-static-parsing.md)).

## Consequences

- Middleware registered with `$this->middleware()` inside a constructor is not visible on the controller artifact. This is a known limitation. The route artifact's own `middleware` list still includes it, because the router reports it.
- Route-group middleware stays on the route artifact. The controller artifact holds only what the controller declares.
