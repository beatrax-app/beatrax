<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;

/**
 * Contract for the Gmail API client.
 *
 * The production `GmailApiClient` wraps `google/apiclient`'s Gmail
 * service against a live OAuth-authenticated user. The Wave 0
 * `FakeGmailApiClient` implements the same surface by replaying
 * synthesised JSON fixtures so background-job tests can drive the
 * pipeline end-to-end without a real Google grant.
 *
 * Three error sentinels participate in the contract:
 *
 *  - `RateLimitedException` is thrown by the list / get methods when
 *    the provider responds with `rateLimitExceeded` / `userRateLimit
 *    Exceeded` / `dailyLimitExceeded` (HTTP 429 or 403 with the quota
 *    reason). `retryAfterSeconds` carries the provider hint.
 *  - `CursorExpiredException` is thrown by `listHistory()` when the
 *    `startHistoryId` is older than Gmail's ~7-day retention window.
 *    The caller falls back to a date-bounded re-scan.
 *  - Token payloads (refresh tokens, access tokens) never appear in
 *    any thrown exception message — implementations strip them
 *    before re-throwing as one of the typed sentinels above.
 */
interface GmailApiClientContract
{
    /**
     * `users.messages.list` with a `from:(...)` server-side filter
     * over the given sender patterns, paged at 100 messages per
     * call. The caller walks pages until `nextPageToken` is null.
     *
     * When `$windowStart` is non-null, the query also carries
     * Gmail's `after:` operator (unix-timestamp form for sub-day
     * precision) so the IncrementalScanJob's cursor-expiry fallback
     * walk can be date-bounded to `last_scan_at - 7 days` rather
     * than walking the entire allow-list history capped only by the
     * 500-message defensive cap.
     *
     * @param  list<string>  $senderPatterns
     * @return array{messages: list<array{id: string, threadId: string}>, nextPageToken: ?string, historyId: ?string, resultSizeEstimate: int}
     */
    public function listSenderMessages(
        int $inboxId,
        array $senderPatterns,
        ?string $pageToken,
        ?DateTimeImmutable $windowStart = null,
    ): array;

    /**
     * `users.messages.get?format=raw`. Returns the RFC 822 byte
     * stream — production decodes Gmail's base64url payload before
     * returning so the caller always sees real raw MIME bytes.
     */
    public function getRawMessage(int $inboxId, string $providerMessageId): string;

    /**
     * `users.history.list`. The caller replays
     * messagesAdded / messagesDeleted entries since the cursor was
     * set. A 404 surface aside historyId expiry as
     * `CursorExpiredException` so the caller falls back to a
     * date-bounded re-scan.
     *
     * @return array{history: list<array<string, mixed>>, historyId: ?string}
     */
    public function listHistory(int $inboxId, string $startHistoryId): array;

    /**
     * Daily-discovery query: `users.messages.list` with a broad
     * `subject:(...)` keyword filter minus the `-from:(...)` allow-list
     * of senders the caller already knows about. Returns one entry per
     * matching message with the minimal sender metadata the discovery
     * loop needs to populate `discovered_senders` rows — no body bytes
     * are fetched here, so this surface NEVER persists a `.eml` blob.
     *
     * The production implementation pairs the list call with a per-
     * message `users.messages.get?format=metadata&metadataHeaders=['From','Date']`
     * fetch so the response carries the parsed sender address + name +
     * internalDate without dragging the full RFC 822 body across the
     * wire. The Fake collapses all that into a single fixture replay.
     *
     * `$pageToken` is the prior page's `nextPageToken` for walking past
     * the 100-message-per-page Gmail cap; the caller paginates until
     * `nextPageToken` returns null OR a per-job hard cap is reached.
     *
     * The discovered-message entries are typed as `array<string, mixed>`
     * rather than a fixed-shape array — the caller defensively narrows
     * each field at the foreach boundary so a future Fake variant or a
     * production-side response shape drift cannot crash the daily scan.
     *
     * @param  list<string>  $keywords
     * @param  list<string>  $excludeSenders
     * @return array{messages: list<array<string, mixed>>, nextPageToken: ?string}
     */
    public function listDiscoveryCandidates(
        int $inboxId,
        array $keywords,
        array $excludeSenders,
        ?string $pageToken = null,
    ): array;
}
