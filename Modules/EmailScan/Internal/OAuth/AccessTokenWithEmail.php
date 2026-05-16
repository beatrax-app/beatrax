<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use DateTimeImmutable;

/**
 * Immutable carrier for the access-token + refresh-token + identifying
 * email returned by the OAuth provider after an authorisation-code
 * exchange or a refresh-token rotation.
 *
 * Pure data — no methods beyond the constructor. The OAuth callback
 * controller writes the refresh token + email into the inbox row and
 * the chmod-600 JSON via OAuthSecretsRepository; this DTO is the
 * single-trip carrier across that boundary.
 *
 * The refresh_token is null when the provider's response did not
 * include one — typically on a refresh-only rotation where the
 * existing refresh token stays valid.
 */
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
