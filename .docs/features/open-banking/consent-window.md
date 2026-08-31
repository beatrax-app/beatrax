# The consent window

A PSD2 consent is a span with a start, an end, and a band near the end where the
reader should be warned but nothing is broken yet. Four places in this module
decided that span, four different ways:

- the queued sync job, parsing the stored expiry and asking Carbon `isFuture()`;
- `OpenBankingFetchService`, with a byte-identical copy of the same three lines;
- `OpenBankingConnectionQuery`, with a `match(true)` over `lessThanOrEqualTo()`
  plus its own 14-day constant;
- the daily scheduler in `routes/console.php`, in SQL, against the database's
  `now()` rather than the injected clock.

`Modules\OpenBanking\Internal\Support\ConsentWindow` is now the only one. It owns
the 180-day length the app asks the ASPSP for, the 14-day band the reader is
warned in, and the comparison itself.

## Why one owner and not four agreeing copies

This repository has already shipped the bug this prevents. In SQLite,
`'2026-04-17' >= '2026-04-17 00:00:00'` is **false**: a `DATE` and a `DATETIME`
holding the same instant sort against each other as text, and the boundary day
disappears. `consent_expires_at` is a string column read straight off a
query-builder row, so any caller that compares it as a string — or that parses it
one way while a neighbour parses it another — decides the boundary day
differently from its neighbours. One connection then reads as live on the
scheduler's side and expired on the settings page's, or the reverse.

`ConsentWindow::fromStoredRow()` is the only door for a raw connection row: it
parses to a `CarbonImmutable` and compares instants. `endingAt()` is the door for
values a caller has already parsed.

It takes the **row**, not one column of it, because the window now has two
independent endings — the calendar running out, and the bank taking the consent
away — and a door that accepted only `consent_expires_at` was a door every
caller forgot the second at.

## The API

- `ConsentWindow::expiresAfter($issuedAt)` — the expiry to record for a consent
  granted now. Both `OpenBankingConnectController` (which asks the aggregator for
  `valid_until`) and `OpenBankingCallbackController` (which records what came
  back) call it, so the span requested and the span stored cannot drift apart. A
  connection that looks live locally after the bank has revoked it is the failure
  that costs the reader a silent, unexplained sync outage.
- `isLive()` — the fetchable/not-fetchable boundary the job and the fetch service
  re-check on pickup. False for a revoked consent whatever the calendar says.
- `isExpiringSoon()` — live, but inside the warning band.
- `isRevoked()` — the aggregator has refused the session.
- `status()` — the same questions as a `ConsentStatus`, for the read model.
- `constrainToLive($query, $now)` — the same boundary expressed as a `where` on a
  query builder. The daily scheduler enumerates every connection row there is, so
  it cannot load them to ask each one; this keeps the numbers and the comparison
  in the same class the loaded-row callers use. It names both columns itself,
  because a caller passing one of them is a caller that can pass only one.

## Why the band is 14 days and the span is 180

180 days is the maximum a PSD2 AIS consent is normally granted for without
re-authentication, so it is what the app asks for; asking for less would only
make the reader re-link sooner. 14 days is the warning band because re-linking is
not a click — it is a full browser round trip to the bank, an SCA challenge, and
a return to this app. A warning that arrives with a day left is a warning the
reader cannot act on before the connection stops.

## Revocation is not expiry

A PSD2 session can end long before its window does: the reader withdraws consent
in their bank's app, the bank invalidates it, or the aggregator retires it. What
arrives here is a 401 or 403 on the next fetch, while `consent_expires_at` still
sits months in the future.

Recording the refusal only as `last_attempt_status = consent_failed` left the
connection tile reading **Connected** off a date that was still fine, which is
the silent outage this page's own `expiresAfter()` note warns about. So the
refusal is written onto the row: `OpenBankingSyncRunner` stamps
`consent_revoked_at` in the same UPDATE that records the failed attempt, and
`ConsentStatus::Revoked` is what every surface then reads —

- the transparency pill says "Ended by your bank — reconnect", not "Expired";
- the mobile status row and the consent banner name the withdrawal rather than
  an expiry, because a reader sent to check a date that is still valid has been
  told the wrong thing;
- `isLive()` is false, so the fetch service, the queued job and the daily
  scheduler all stop trying — another attempt against a refused session earns
  another 401 and nothing else.

`ConsentStatus::needsReconnect()` is what the surfaces ask, so the two endings
cannot drift apart: both are red, both offer the reconnect path, and neither
surface has to list the cases and remember to add one.

`OpenBankingCallbackController` clears `consent_revoked_at` when a re-link
succeeds — a fresh consent is exactly what the stamp was waiting for — and its
compensating rollback restores the prior value if the secrets write then fails.

## See also

- [`architecture.md`](architecture.md) — the module map and the consent dance
  that produces the expiry stored here.
- [Fetch cursor](fetch-cursor.md) — the other boundary a fetch re-checks: how far
  the connection may claim to have read.
