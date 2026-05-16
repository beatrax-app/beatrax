<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * In-memory carrier for the persistent + freshly-refreshed token pair
 * the OAuth secrets repository hands back when the background scanner
 * needs to call the provider on behalf of a connected inbox.
 *
 * The persistent half (the long-lived rotation token + scope +
 * expires_at) lives in the chmod-600 JSON repository on disk; the
 * volatile half (the cached short-lived token) may be null when the
 * repository has not yet refreshed it. Callers always treat the
 * volatile field as a hint — when null or near-expiry they request a
 * fresh refresh via the OAuth provider, which writes the new pair
 * back through the repository's rotate() method.
 *
 * The DTO is pure data — no methods beyond the constructor.
 */
final class InboxCredentials extends Data
{
    public function __construct(
        public readonly int $inboxId,
        public readonly string $provider,
        public readonly string $refreshToken,
        public readonly string $scope,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly ?string $accessToken,
    ) {}
}
