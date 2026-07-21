<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

// In-memory carrier for the persistent + freshly-refreshed token pair
// OAuthSecretsRepository hands back. The persistent half lives in the
// chmod-600 JSON repository; the volatile accessToken may be null and
// is always treated as a hint, refreshed near-expiry via rotate().
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
