<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

use Firebase\JWT\JWT;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;

final readonly class EnableBankingJwtSigner
{
    private const string ISSUER = 'enablebanking.com';

    private const string AUDIENCE = 'api.enablebanking.com';

    // The client assertion's own lifetime, which is not the lifetime of the
    // session it is exchanged for.
    private static function ttlSeconds(): int
    {
        return Duration::Hour->seconds();
    }

    public function __construct(private Clock $clock) {}

    public function sign(string $privateKeyPem, string $applicationId): string
    {
        $now = $this->clock->now()->getTimestamp();

        return JWT::encode(
            [
                'iss' => self::ISSUER,
                'aud' => self::AUDIENCE,
                'iat' => $now,
                'exp' => $now + self::ttlSeconds(),
            ],
            $privateKeyPem,
            'RS256',
            $applicationId,
        );
    }
}
