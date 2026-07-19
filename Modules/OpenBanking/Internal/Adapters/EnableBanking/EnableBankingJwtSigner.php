<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

use Firebase\JWT\JWT;
use Modules\Core\Public\Contracts\Clock;

/**
 * Builds the RS256 JWT bearer Enable Banking requires on every API call
 * [CITED: enablebanking.com/docs/api/quick-start/].
 *
 * Header: `{"alg": "RS256", "kid": $applicationId}`.
 * Claims: `{"iss": "enablebanking.com", "aud": "api.enablebanking.com",
 * "iat": $now, "exp": $now + 3600}`.
 *
 * Delegates entirely to `firebase/php-jwt`'s `JWT::encode()` — per
 * RESEARCH.md's Don't Hand-Roll table, hand-rolling base64url +
 * `openssl_sign` would re-introduce exactly the edge cases (clock-skew
 * leeway, algorithm confusion) the vendored library already handles.
 *
 * The RSA private key is used ONLY to sign locally; it never crosses
 * the wire — only the resulting signed token does.
 */
final class EnableBankingJwtSigner
{
    private const ISSUER = 'enablebanking.com';

    private const AUDIENCE = 'api.enablebanking.com';

    private const TTL_SECONDS = 3600;

    public function __construct(private readonly Clock $clock) {}

    public function sign(string $privateKeyPem, string $applicationId): string
    {
        $now = $this->clock->now()->getTimestamp();

        return JWT::encode(
            [
                'iss' => self::ISSUER,
                'aud' => self::AUDIENCE,
                'iat' => $now,
                'exp' => $now + self::TTL_SECONDS,
            ],
            $privateKeyPem,
            'RS256',
            $applicationId,
        );
    }
}
