# Test references are read from source text

A test reaches an artifact only through its single `subject`, which `uses(X::class)` or the path convention supplies. A feature test such as `tests/Feature/Orders/CreateOrderTest.php` infers `App\Orders\CreateOrder`, which usually matches nothing, so Impact and `necromancer:affected-tests` are blind to most feature tests. What a test exercises exists only in its body, so the scan reads each collected test file with `nikic/php-parser` and records a `references` Discovered Fact: the application-namespace classes it names in code (`X::class`, `new X`, `X::method()`, resolved through imports) and the literal route names it passes to `route()`. This extends [0020](0020-dispatch-facts-are-read-from-source-text.md)'s exception on the same terms: source text supplies a fact with no runtime or reflected form, never discovers artifacts, and never overrides another fact. Test files were already read as text for their subject and methods.

Each reference becomes a `tested_by` Relationship with `match: reference`, rather than a new type. Every consumer of `tested_by` (Impact, affected tests, the Artifact Graph, the Knowledge Bundle) picks it up unchanged, and a referenced artifact's test is directly affected, like a subject-matched one. When a reference and the subject name the same artifact, the two merge and the subject's `exact`/`namespace` match wins.

## Considered Options

- **A new `exercises` Relationship (test → artifact)** — keeps "tests this" apart from "mentions this", but every consumer would need to learn a second test type and a ranking rule between them. Rejected.
- **Count `use` imports** — trivial to read, but an import says nothing on its own and stale imports are common. Rejected: an imported name counts only where the code uses it.
- **Count every class, vendor included** — would turn `Mail::fake()` and `Carbon::now()` into unresolved Relationships on every test. Rejected: only classes in the application namespace are recorded.
- **Drop models that are only built through factories** — would cut the `User::factory()` noise in most feature tests, but a test that builds a model does depend on it, and tests are Boundary Nodes, so the extra Relationships never fan out further. Rejected.

## Consequences

- Changing a widely used model, such as `User`, directly affects every test that references it. That is accurate, but it makes the directly-affected tier larger for such models.
- An application class that isn't a collected artifact (`App\Support\Money`, a notification) or a middleware class still yields a `tested_by` Relationship, marked unresolved, as an unmatched subject does. Under `necromancer:scan --only=tests`, every reference is unresolved.
- Only literal route names are seen; `route($name)`, URIs passed to `$this->get('/orders')`, and classes reached through helpers or parent test classes are not.
- References are collected by default, so the first scan after upgrading reports drift for every test that references something.
