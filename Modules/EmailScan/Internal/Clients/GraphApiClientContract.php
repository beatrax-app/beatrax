<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;

/**
 * Contract for the Microsoft Graph API client.
 *
 * Mirrors the test-seam pattern established by `GmailApiClientContract`:
 * the production `GraphApiClient` wraps live Graph HTTP calls; the
 * Wave 0 `FakeGraphApiClient` replays synthesised JSON fixtures.
 * Both implement this interface so background-job tests can rebind the
 * contract to the Fake via `$this->app->instance(...)` without any
 * production code path knowing the difference.
 *
 * Error sentinels participate in the contract:
 *
 *  - `RateLimitedException` is thrown when Graph responds with HTTP 429
 *    (`TooManyRequests`). `retryAfterSeconds` carries the value of the
 *    `Retry-After` response header so the caller can transition the
 *    inbox state and let the queue worker reschedule with the right
 *    back-off envelope.
 *
 *  - `CursorExpiredException` is thrown by `deltaPage()` when Graph
 *    returns HTTP 410 / `syncStateNotFound`. The caller falls back to
 *    a date-bounded re-baseline rather than treating it as a hard
 *    error.
 *
 *  - Token payloads (refresh tokens, access tokens, bearer headers)
 *    never appear in any thrown exception message — implementations
 *    strip them before re-throwing as one of the typed sentinels above.
 *
 * Method contract details (mirrors what the production wrapper does
 * against `https://graph.microsoft.com/v1.0/...`):
 *
 *  - `listSenderMessagesPaged` issues
 *    `GET /me/messages?$filter=(from/emailAddress/address eq 'a' or ...) and receivedDateTime ge {windowStart}&$orderby=receivedDateTime desc&$top=100&$select=id,from,subject,receivedDateTime`
 *    on the first page and follows the `@odata.nextLink` URL verbatim
 *    on subsequent pages.
 *
 *  - `getRawMessage` issues `GET /me/messages/{id}/$value` which
 *    returns the raw RFC 822 byte stream directly (unlike Gmail which
 *    returns a base64url-encoded `raw` field).
 *
 *  - `deltaPage` issues
 *    `GET /me/mailFolders/inbox/messages/delta?$filter=receivedDateTime ge {now}`
 *    on the baseline call (when `$deltaLink === null`) and follows
 *    the prior `@odata.deltaLink` URL verbatim on incremental walks.
 *
 *  - `listDiscoveryCandidatesPaged` is reserved for the discovery
 *    plan; current implementations return an empty page so any caller
 *    wiring against the contract compiles without exercising live
 *    keyword search.
 */
interface GraphApiClientContract
{
    /**
     * Backfill walk over Graph messages restricted to the configured
     * sender allow-list + a receivedDateTime lower bound. Paged at 100
     * messages per call via `@odata.nextLink`.
     *
     * @param  list<string>  $senderPatterns
     * @return array{messages: list<array<string, mixed>>, nextLink: ?string}
     */
    public function listSenderMessagesPaged(
        int $inboxId,
        array $senderPatterns,
        DateTimeImmutable $windowStart,
        ?string $nextLink,
    ): array;

    /**
     * Fetch the raw RFC 822 byte stream for one message. Graph's
     * `/$value` endpoint returns the bytes directly with no transport
     * wrapper — production callers persist the response verbatim into
     * the .eml blob store.
     */
    public function getRawMessage(int $inboxId, string $providerMessageId): string;

    /**
     * Establish or walk the Graph `$delta` cursor.
     *
     * When `$deltaLink === null` the call is the post-backfill baseline
     * — it returns an empty `value` plus the first `@odata.deltaLink`
     * which the caller persists into `inbox_scan_state.last_delta_link`
     * for the incremental-scan plan to walk from.
     *
     * When `$deltaLink !== null` the implementation follows the URL
     * verbatim (the cursor token + filter are embedded in the URL by
     * Graph). On 410 / `syncStateNotFound` the implementation throws
     * `CursorExpiredException::graph()`.
     *
     * @return array{messages: list<array<string, mixed>>, deltaLink: ?string, nextLink: ?string}
     */
    public function deltaPage(int $inboxId, ?string $deltaLink): array;

    /**
     * Reserved for the discovery scan plan. Current implementations
     * return an empty page so the discovery loop is inert until the
     * matcher phase wires the real keyword query.
     *
     * @param  list<string>  $keywords
     * @param  list<string>  $excludeSenders
     * @return array{messages: list<array<string, mixed>>, nextLink: ?string}
     */
    public function listDiscoveryCandidatesPaged(
        int $inboxId,
        array $keywords,
        array $excludeSenders,
        ?string $nextLink,
    ): array;
}
