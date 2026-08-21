<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;

// $refreshToken is null when the provider's response omitted one, which is the
// normal shape of a refresh-only rotation.
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
