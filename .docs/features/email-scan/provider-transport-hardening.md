# Provider transport hardening

Every request this module makes to a mail provider carries an OAuth
bearer token with read access to the user's mailbox. Leaking one is the
worst outcome the module has. This page describes the guards that stop a
bearer token reaching a host it was not minted for, and why the Graph
client carries several that the Gmail client does not.

## The threat: a URL that comes back from the server

Microsoft Graph paginates by returning a fully-formed URL in the response
body — `@odata.nextLink` for an ordinary page, `@odata.deltaLink` for a
delta cursor. The client follows those URLs **verbatim**. It has to:
Graph embeds the skip token and the original `$filter` inside the link,
and the skip token is opaque, so reconstructing the query from parts
would be lossy.

That makes the next request's URL a value that arrives over the network
rather than one the code composed. A malformed or hostile
`@odata.nextLink` is a URL of the attacker's choosing that the client is
about to attach a mailbox bearer token to. Everything below exists
because of that one property.

Delta links are also persisted as scan cursors and replayed on a later
run, so a bad link is not merely a single bad request — it is a bad
request stored and repeated until the cursor is reset.

## The guard

`assertAllowedUrl()` runs on **every** request, and it checks two things
before any bearer is attached:

1. The scheme is `https`. Anything else is refused, so a downgraded link
   cannot put a token on the wire in clear text.
2. The host, lowercased, is in `ALLOWED_HOSTS` — exactly
   `['graph.microsoft.com']`.

Both failures raise `UnsafeProviderRequestException`. The message names
the offending host (or `(unparseable)`), which is safe to log because the
host is not the secret.

The allow-list is a single entry on purpose. The regional clouds —
`graph.microsoft.de`, `graph.microsoft.us` — are deliberately excluded.
They are legitimate Microsoft endpoints, but adding one widens the set of
hosts that may receive a user's token, so it is a reviewed config change
rather than something a future edit adds casually.

### The guard sits at the HTTP boundary

`assertAllowedUrl()` is called inside `getJson()`, immediately before the
request, rather than at the point where `@odata.nextLink` is parsed out
of the body. That placement is the whole point: put it at the parse site
and any *other* response path that later learns to follow a URL bypasses
it. At the HTTP boundary there is no path to the network that skips it.

The same call in `getRawMessage()` is redundant by construction — the URL
is a compile-time constant base plus a regex-validated message id — and
is kept anyway so that every bearer-attaching path clears the identical
gate. Redundant-looking checks are cheaper than reasoning about which
paths are exempt.

### Redirects are refused

The Guzzle client is built with `allow_redirects => false`.

Without it the guard is defeated by one hop: `assertAllowedUrl()` passes
on a `graph.microsoft.com` URL, the bearer is attached, Graph (or
something impersonating a response) returns a 3xx to another host, and
Guzzle follows it — carrying the Authorization header past a check that
already ran. Graph never issues 3xx on these endpoints, so disabling
redirects costs nothing and removes the bypass.

The client also pins `timeout => 30` and `connect_timeout => 10` so a
hanging provider cannot occupy a queue worker indefinitely.

### Message ids are validated before use

`getRawMessage()` interpolates the provider message id into a path, so
the id is checked against `MESSAGE_ID_PATTERN` —
`/^[A-Za-z0-9._%=+\-]{1,512}$/` — and rejected outright otherwise. The
character class is what Graph actually issues; the 512 cap bounds the
URL. A message id containing a slash or a dot-dot cannot walk the path.

## Why the Gmail client looks different

`GmailApiClient` has no host allow-list, no `assertAllowedUrl()`, no
redirect suppression and no id pattern. That is not an oversight and it
should not be "fixed" by copying the Graph guards across.

Gmail is driven through the official `Google\Service\Gmail` SDK. The
client passes *parameters* — a query string, a `pageToken`, a message id
— and the SDK composes the URL from its own compiled-in endpoints. There
is no point at which a URL from the response body becomes the next
request's target: `nextPageToken` is an opaque string handed back as a
parameter, not a link that gets followed. The attacker-controlled-URL
threat that motivates the Graph guards does not exist on that path.

The one thing to keep in mind: this reasoning depends on the SDK owning
URL construction. Hand-rolling a raw Guzzle call against a Gmail endpoint
would reintroduce the threat and would need the Graph treatment.

## Token refresh and single-use rotation

Both clients refresh an access token when it is missing, empty, or within
**60 seconds** of its stamped expiry — deliberately *before* the expiry,
never on it, so a long-running request cannot start with a token that
dies mid-flight.

Microsoft rotates refresh tokens **single-use**: every refresh returns a
new refresh token and invalidates the one just spent. So the returned
token must be persisted on every refresh, which is what
`rotateRefreshToken()` does. Skipping that persist — or persisting only
the access token — silently breaks the *next* refresh, not the current
one, which makes it a failure that shows up hours later and looks
unrelated to the change that caused it. The
`$fresh->refreshToken ?? $creds->refreshToken` fallback keeps the
existing token when a provider returns none.

A refresh that fails with `invalid_grant` means the user's consent is
gone and no retry will fix it. That path raises
`ReconsentRequiredException` and dispatches `InboxTokenFailed` so the UI
can prompt for re-consent rather than the scan retrying forever.

`lookupInboxUserId()` returns `0` rather than throwing when the inbox row
has vanished. The inbox can be deleted between a scan starting and its
token refresh failing, and the error-recovery path still has to complete
— throwing there would replace a recoverable consent error with an
unhandled one during error handling.

## Error responses never leak

Guzzle exception messages can contain the full request, including
headers. Both clients route provider failures through a mapper —
`GraphErrorMapper::mapErrorResponse()` for HTTP error responses and
`safeMessage()` for transport errors — rather than embedding the raw
exception text. Anything reaching a log or a user-facing error goes
through that scrubbing first.

JSON that fails to decode raises `ProviderTransportException`; a body
that decodes to a non-array returns `[]` rather than throwing, so a
surprising-but-harmless response shape degrades to an empty page instead
of failing the scan.

## Graph query quirks worth knowing

These are provider constraints, not choices:

- Graph rejects `$filter contains(subject, ...)` on the messages
  collection, so `$search` is the only keyword match available.
- Graph rejects `$orderby` alongside `$search`, so when searching,
  neither is sent — results come back in provider order.
- Graph rejects a `not from/...` predicate alongside `$search`, so the
  discovery exclude-list can only be applied client-side after the
  fetch. A message with no readable from-address is kept, because the
  exclude list can only ever remove an already-known sender.
- `$search` wants the whole KQL expression wrapped in its own outer pair
  of double quotes.
- OData escapes a single quote inside a string literal by doubling it, so
  `o'brien` must go out as `o''brien`.
- `/messages/{id}/$value` returns raw RFC 822 bytes directly, with no
  base64 or JSON envelope — unlike Gmail's `users.messages.get`, whose
  `raw` field is base64url with padding stripped.

On the delta path, a caller-pinned anchor wins over the cursor's own
baked-in filter: a multi-hour backfill must fix its lower bound before
the walk starts, or messages arriving mid-walk slip past both filters and
are never seen again.

## Related pages

- [`EmailScan` architecture](architecture.md) — the module boundary, the
  OAuth handshake and the scan state machine.
- [`EmailScan` code map](code.md) — where each client and job lives.
- [How to test `EmailScan`](how-to-test.md) — the fake clients that
  mirror these response shapes.
