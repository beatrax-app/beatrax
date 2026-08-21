<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use RuntimeException;

// Two rows in enable-banking-transactions.json (Albert Heijn -3.99 EUR on
// 2026-02-02, Coolblue 2 +11.67 EUR on 2026-02-05) exist verbatim in the ASN
// CAMT.053 fixture, one DBIT and one CRDT, so both adapters must hash them alike.
final class EnableBankingFixtures
{
    public static function root(): string
    {
        return dirname(__DIR__).'/Fixtures';
    }

    /**
     * @return array{transactions: list<array<string, mixed>>, continuation_key: mixed}
     */
    public static function transactions(): array
    {
        return self::decode(self::root().'/enable-banking-transactions.json');
    }

    /**
     * @return array{balances: list<array<string, mixed>>}
     */
    public static function balances(): array
    {
        return self::decode(self::root().'/enable-banking-balances.json');
    }

    // The same two real-world entries as the overlapping EB rows
    // (entry_reference 20260202-898406 / 20260205-2850362).
    public static function overlappingCamt053FixturePath(): string
    {
        return base_path('tests/fixtures/asn-camt053-sample-1.xml');
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read fixture: {$path}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
