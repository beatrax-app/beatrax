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
final class CounterpartyKey
{
    // Named where the key is minted rather than where the import pipeline
    // happens to sit: three modules produce this column and the sentinel has
    // to mean the same thing in all of them.
    public const string NONE = '_no_counterparty';

    // The logical key, not a table.column: `merchants.normalized_name` is
    // compared against this value, so the two must derive under one domain.
    public const string DOMAIN = 'counterparty-normalized';

    public function __construct(
        private readonly FingerprintComposer $fingerprints,
        private readonly BlindIndexCodec $blindIndex,
        private readonly SessionFactory $session,
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
}
