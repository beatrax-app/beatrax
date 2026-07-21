<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;

// Immutable carrier for the access-token + refresh-token + identifying
// email returned by the OAuth provider after an authorisation-code
// exchange or a refresh-token rotation. refreshToken is null when the
// provider's response omitted one (typically a refresh-only rotation).
final readonly class AccessTokenWithEmail
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?DateTimeImmutable $expiresAt,
        public string $scope,
        public string $email,
    ) {}
}
