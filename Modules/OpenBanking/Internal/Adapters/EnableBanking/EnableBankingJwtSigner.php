<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

use Firebase\JWT\JWT;
use Modules\Core\Public\Contracts\Clock;

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
