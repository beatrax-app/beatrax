# A controller hands the work to an action

An HTTP controller in this repository resolves input, delegates, and returns a
response. Nothing else. The rule is enforced by
`tests/Contracts/AControllerHandsTheWorkToAnActionArchTest.php`, which measures
four things and fails on any of them.

Before that guard existed, fourteen controllers held 1,973 lines between them.
Among the things a route was carrying: an OAuth token exchange with its own
failure-to-sentence mapping, two compensating rollbacks around a
write-after-commit, a command allow-list split by destructive tier, an
egress allow-list and the open-redirect check that guards it, a partial-line
holdback that exists so a secret cannot be split across two redaction passes,
and a `SELECT sqlite_version()`.

## What is measured

| # | Rule | Ceiling |
|---|---|---|
| 1 | Names no database type | none allowed |
| 2 | Statements in any one method | `ControllerShape::MAX_STATEMENTS` |
| 3 | Cognitive complexity of any one method | `ControllerShape::MAX_COMPLEXITY` |
| 4 | Methods declared besides the constructor | `ControllerShape::MAX_METHODS` |

**Rule 1** covers `Illuminate\Database\`, `Illuminate\Contracts\Database\`, the
`DB` facade, and any Eloquent model under `Modules\<X>\Models\`. Models are a
deliberate cross-module read seam everywhere else in this tree
([module boundaries](../architecture/module-boundaries.md)); a controller is the
one place they are refused, because a model in scope is one `->update()` away
from making the HTTP layer a writer, and the row it would write has no test that
reaches it by any route other than a request.

**Rule 2** counts semicolon-terminated statements inside the method body, at any
brace depth, including the bodies of closures the method declares. That last
part is deliberate: a pump handed to `new StreamedResponse(function () { … })`
is still the work of the method that wrote it, which is also how the cognitive
complexity scoring folds a closure in.

**Rule 3** reuses the ported analyser scoring in
[analyser rules enforced locally](analyser-rules-enforced-locally.md). The
tree-wide ceiling is 15; a controller's is far lower, because deciding is
exactly the part that belongs in an action.

**Rule 4** exists so rules 2 and 3 cannot be satisfied by spreading a method
across private helpers. A controller that needs more than a handful of methods
is carrying something that is not HTTP.

## What is deliberately not a violation

The numbers are sized to leave room for the things that genuinely are the
response, and a sweep that moves these behind an action makes the code worse:

- **Streaming.** The SSE pump in `ArtisanStreamController` — the tail, the
  frame, its `id:`, the flush, the abort check and the wall-clock deadline —
  stays written out in the controller. An event frame is the response being
  written, not work the response reports on. What a finished run *means* —
  which exit code is authoritative, whether it was cancelled, and the audit row
  it settles — is an action, because that answer is the same however it is
  asked for.
- **Redirects, flashes, headers and status selection.** Choosing between
  `away()`, a flashed failure and a flashed success is the controller's whole
  job. An action returns an outcome; the controller decides what 409, 200 and
  503 mean for it.
- **OAuth state and PKCE.** `issueState`, `consumeState`, `storePkceVerifier`
  and `consumePkceVerifier` are one-shot request glue: the state exists only to
  be read back by the callback request, and the verifier only to survive
  between the two. Both stay at the top of the controller, where the redirect
  they authorise is in view. Everything downstream of them — the exchange, the
  writes, the compensations — is not.
- **Framework interplay.** A CSRF-exempt GET, a keepalive `POST` that cannot
  set headers, a body returned instead of a status because the Android bridge
  rewrites statuses: these are notes about the transport and they belong on the
  class that speaks it.
- **Server-side re-checks of a UI gate.** `DestructiveSpawnController`
  re-checks all three TripleGateModal locks before it delegates. They read the
  session and the request, they answer 403, and they sit beside the route so a
  reader of `Routes/web.php` finds them.

## Where the action goes

Beside the ones already there: `Modules/<X>/Internal/Actions/` when nothing
outside the module calls it, `Modules/<X>/Public/Actions/` when something does.
`Public` is a contract surface, and
`tests/Contracts/PublicSurfaceArchTest.php` fails a `Public` class with no
consumer outside its own module — so `Internal` is the default and the right
answer for every action a controller alone invokes.

## Related

- [Analyser rules enforced locally](analyser-rules-enforced-locally.md) — the
  cognitive-complexity scoring rule 3 reuses, and the method-count rule it is
  modelled on
- [Writing an arch invariant](arch-invariants.md) — the house mechanics every
  guard in `tests/Contracts/` shares
- [Module boundaries](../architecture/module-boundaries.md) — why `Models\` is a
  read seam everywhere except here
