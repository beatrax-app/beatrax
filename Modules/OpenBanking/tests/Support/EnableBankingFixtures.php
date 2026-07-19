<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use RuntimeException;

/**
 * Shared accessors for the synthetic Enable Banking JSON fixtures under
 * `Modules/OpenBanking/tests/Fixtures/`, plus the path to the existing
 * ASN CAMT.053 fixture representing the SAME real-world booked
 * transactions (Albert Heijn -3.99 EUR on 2026-02-02, Coolblue 2 +11.67
 * EUR on 2026-02-05).
 *
 * This pairing is what the Wave 2 `OpenBankingFingerprintParityTest`
 * consumes to prove the EB adapter and the CAMT.053 adapter converge on
 * identical `FingerprintComposer` hashes for the overlapping rows — i.e.
 * zero net-new duplicates when both sources describe the same account.
 *
 * Per D-01's field-mapping table (RESEARCH.md Architecture Patterns §2),
 * `booking_date` drives both `bookedAt` and `postedAt` (zeroed to
 * midnight) and the sign convention is DBIT-negates — the two overlapping
 * rows in `enable-banking-transactions.json` were chosen because they
 * exist verbatim (same date, amount, currency, counterparty) in the
 * committed CAMT.053 fixture, one DBIT and one CRDT so both direction
 * rules are exercised.
 */
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

    /**
     * Absolute path to the existing ASN CAMT.053 fixture that carries the
     * SAME real-world booked transactions as the overlapping rows in
     * `enable-banking-transactions.json` (entry_reference
     * 20260202-898406 / 20260205-2850362), for the Wave 2 fingerprint-
     * parity contract test. Reused rather than duplicated per this
     * plan's "do not invent a new CAMT fixture" instruction.
     */
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
