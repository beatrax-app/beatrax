<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\BlindIndexCodec;

// The one producer of `transactions.counterparty_normalized`. Everything
// downstream — the fingerprint, the merchant join, the recurring cluster —
// copies what this returns, so a second producer that skipped the key would
// store a second form of the same merchant inside a UNIQUE index.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final readonly class CounterpartyKey
{
    // Aliased, not redeclared: the guards that read a blind-index column back
    // resolve the sentinel from the registry, and two spellings of it would
    // make an unkeyed value look like a defect on one side and a decision on
    // the other.
    public const string NONE = BlindIndexCodec::SENTINEL;

    // The logical key, not a table.column: `merchants.normalized_name` is
    // compared against this value, so the two must derive under one domain.
    public const string DOMAIN = BlindIndexCodec::DOMAIN_COUNTERPARTY_NORMALIZED;

    // A separate domain because an IBAN is not a name: the income detector
    // clusters on whichever it has, and one domain would let a merchant whose
    // normalised name happened to equal an IBAN string match that payer.
    public const string DOMAIN_IBAN = BlindIndexCodec::DOMAIN_COUNTERPARTY_IBAN;

    public function __construct(
        private FingerprintComposer $fingerprints,
        private BlindIndexCodec $blindIndex,
        private SessionFactory $session,
    ) {}

    public function forName(?string $rawName, int $userId): string
    {
        if ($rawName === null || trim($rawName) === '') {
            return self::NONE;
        }

        return $this->forNormalized($this->fingerprints->normalize($rawName), $userId);
    }

    // The sentinel is stored verbatim. It records the absence of a
    // counterparty rather than naming one, so keying it would conceal nothing
    // and would cost the two guards that compare a stored value against it.
    public function forNormalized(string $normalized, int $userId): string
    {
        if ($normalized === '' || $normalized === self::NONE) {
            return self::NONE;
        }

        return $this->blindIndex->derive(self::DOMAIN, $normalized, $userId, ($this->session)());
    }

    // The income detector's clustering key when the payer has an IBAN. It is
    // decrypted from `transactions.counterparty_iban` to be computed, so
    // storing it verbatim put back the identifier the AEAD column protects.
    public function forIban(string $iban, int $userId): string
    {
        $normalized = self::normalizeIban($iban);

        return $normalized === ''
            ? self::NONE
            : $this->blindIndex->derive(self::DOMAIN_IBAN, $normalized, $userId, ($this->session)());
    }

    // The one spelling of an IBAN this domain hashes. The enable-time sweep
    // re-derives stored IBANs without a session and has to reach the same bytes
    // this does, so the convention lives here rather than in each caller.
    // Interior spacing is part of the value: two spellings key apart.
    public static function normalizeIban(string $iban): string
    {
        return mb_strtoupper(trim($iban));
    }

    // The other question about the same string: is it an IBAN, and which
    // characters does a card mask. The u modifier is load-bearing — a bank's
    // web page groups an IBAN with U+00A0, which /\s+/ leaves standing and a
    // byte offset then slices through.
    public static function compactIban(string $iban): string
    {
        return (string) preg_replace('/\s+/u', '', self::normalizeIban($iban));
    }
}
