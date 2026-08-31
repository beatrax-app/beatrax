# EmailScan scenario — synthesised three-sender fixture

**NOT anonymised from real user mail.** Every header, body, amount,
reference, and Message-ID is synthetic and authored from scratch by
hand. Re-running any fixture-refresh script must produce byte-identical
output (no PII drift).

## Fixture surface

Three RFC 822 `.eml` messages, one per seeded system sender, exercising
the three receipt-shaped inboxes the scanner has to support:

| File                                                  | From                                                  | Subject                                                                                | Q-encoded? |
|-------------------------------------------------------|-------------------------------------------------------|----------------------------------------------------------------------------------------|------------|
| `eml/paypal/sample-receipt.eml`                       | "PayPal" <service@paypal.com>                         | `=?UTF-8?Q?Bedankt_voor_je_betaling_aan_Synthetic_Merchant_BV?=`                       | yes        |
| `eml/ics/sample-statement-notice.eml`                 | "ICS Cards" <noreply@ics.nl>                          | Je nieuwe maandafschrift staat klaar                                                   | no         |
| `eml/googleplay/sample-purchase.eml`                  | "Google Play" <googleplay-noreply@google.com>         | Your Google Play Order Receipt                                                         | no         |

The PayPal subject is intentionally Q-encoded so the MIME header parser
(zbateson/mail-mime-parser) is exercised against the spec-compliant
encoding shape.

## API response fixtures

JSON fixtures under `api-responses/gmail/` and `api-responses/graph/`
encode the wire shapes both Fake API clients replay:

### Gmail

| File                                           | Encodes                                                                                                                   |
|------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------|
| `gmail/messages-list-page-1.json`              | Three messages, `nextPageToken` set (drives the multi-page fetch loop).                                                   |
| `gmail/messages-list-page-2-empty.json`        | Empty messages array, no `nextPageToken` (last-page sentinel).                                                            |
| `gmail/messages-get-raw-paypal.json`           | `format=raw` envelope. `raw` is base64url(CRLF-normalised .eml).                                                          |
| `gmail/messages-get-raw-ics.json`              | Same shape, ICS body.                                                                                                     |
| `gmail/messages-get-raw-googleplay.json`       | Same shape, Google Play body.                                                                                             |
| `gmail/history-list-404.json`                  | `users.history.list` 404 — Fake maps to `CursorExpiredException`.                                                         |
| `gmail/rate-limit-403.json`                    | `usageLimits.rateLimitExceeded` — Fake's `simulateRateLimit()` toggle replays this shape and throws `RateLimitedException`.|

### Microsoft Graph

| File                                       | Encodes                                                                                                                |
|--------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| `graph/messages-page-1.json`               | Three messages, `@odata.nextLink` set.                                                                                 |
| `graph/messages-page-2-empty.json`         | Empty `value` array, no nextLink (last-page sentinel).                                                                 |
| `graph/delta-baseline.json`                | Empty initial delta page with `@odata.deltaLink` set (the cursor persisted as `last_delta_link`).                       |
| `graph/delta-410.json`                     | `syncStateNotFound` — Fake's `simulateCursorExpired()` toggle replays this shape and throws `CursorExpiredException`.  |
| `graph/throttle-429.json`                  | `TooManyRequests` — Fake's `simulateRateLimit()` toggle replays this shape and throws `RateLimitedException`.          |

## Expected post-fetch state of `inbox_messages`

After `BackfillInboxJob` / `IncrementalScanJob` walk this corpus
through both Fake clients, the `inbox_messages` table holds three rows
(one per `.eml`), all `status='fetched'`:

| provider_message_id                | sender_email                       | sender_name   | subject                                                       | internal_date          | status   |
|------------------------------------|------------------------------------|---------------|---------------------------------------------------------------|------------------------|----------|
| `paypal-sample-receipt`            | `service@paypal.com`               | `PayPal`      | `Bedankt voor je betaling aan Synthetic Merchant BV`          | 2026-05-11 09:14:21Z   | `fetched`|
| `ics-sample-statement-notice`      | `noreply@ics.nl`                   | `ICS Cards`   | `Je nieuwe maandafschrift staat klaar`                        | 2026-05-12 06:00:13Z   | `fetched`|
| `googleplay-sample-purchase`       | `googleplay-noreply@google.com`    | `Google Play` | `Your Google Play Order Receipt`                              | 2026-05-13 17:45:49Z   | `fetched`|

The PayPal row's `subject` is the *decoded* form — `MimeHeaderParser`
turns the Q-encoded header value into the readable UTF-8 string above. The fixture provides the raw
Q-encoded byte sequence so that parser is exercised on this corpus.

`internal_date` is sourced from Gmail's millisecond-epoch
`internalDate` field on the `messages-get-raw-*.json` fixtures
(matches Graph's ISO-8601 `receivedDateTime` to the second).

## Idempotency

Re-running the synthesis at any future point must NOT touch real user
data and must produce byte-identical output. Any change to the .eml
bodies invalidates the base64url payload in the matching Gmail JSON
fixtures — regenerate both in lockstep.
