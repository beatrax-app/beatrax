<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

// The refresh token is the persistent half, held in the chmod-600 JSON
// repository; $accessToken is only ever a hint, re-minted near expiry.
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
