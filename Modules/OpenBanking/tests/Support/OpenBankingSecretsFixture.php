<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use Carbon\CarbonImmutable;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;

// Every seed here names a reader and a bank, because the store has no address
// that does not. A test that wants two banks connected seeds twice.
final class OpenBankingSecretsFixture
{
    public const string INSTITUTION_ID = 'ASNBNL21';

    public const string SECOND_INSTITUTION_ID = 'SNSBNL21';

    public const string APPLICATION_ID = 'fixture-application-id';

    public const string PRIVATE_KEY_PEM = 'fixture-pem';

    public static function path(int $userId): string
    {
        return storage_path('app/secrets/open-banking/'.$userId.'.json');
    }

    public static function legacyPath(): string
    {
        return storage_path('app/secrets/open-banking.json');
    }

    public static function repository(): OpenBankingSecretsRepository
    {
        /** @var OpenBankingSecretsRepository $repository */
        $repository = app(OpenBankingSecretsRepository::class);

        return $repository;
    }

    public static function seedApplication(int $userId, string $applicationId = self::APPLICATION_ID): void
    {
        self::repository()->saveApplication($userId, $applicationId, self::PRIVATE_KEY_PEM);
    }

    public static function seed(
        int $userId,
        string $institutionId = self::INSTITUTION_ID,
        ?CarbonImmutable $consentExpiresAt = null,
        string $sessionId = 'fixture-session',
    ): void {
        $repository = self::repository();
        $repository->saveApplication($userId, self::APPLICATION_ID, self::PRIVATE_KEY_PEM);
        $repository->rememberScaHost($userId, $institutionId, 'sca.asnbank.example');
        $repository->rememberSession(
            $userId,
            $institutionId,
            $sessionId,
            $consentExpiresAt ?? CarbonImmutable::now()->addDays(180),
        );
    }

    public static function forget(int $userId): void
    {
        foreach ([self::path($userId), self::path($userId).'.tmp'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function forgetLegacy(): void
    {
        foreach ([self::legacyPath(), self::legacyPath().'.tmp'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
