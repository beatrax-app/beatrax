<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// detected_name was copied straight from the clustering key — lower-cased,
// punctuation stripped — so "Domino's Pizza" reached the review screen as
// "domino s pizza". Two sources know better: the user's own naming in
// `merchants`, and the name the bank wrote on the transactions themselves.
final readonly class MerchantDisplayName
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
    ) {}

    // Null when neither source knows a name; callers keep the normalised key,
    // which is still the best string available.
    public function forNormalized(int $userId, string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        return $this->fromMerchants($userId, $normalized)
            ?? $this->fromTransactions($userId, $normalized);
    }

    // A series whose stored name is still the clustering key was written before
    // this lookup existed, or by a sweep that could not read the name; no other
    // pass revisits those rows, so the next detection is what heals them.
    public function healed(string $storedName, int $userId, string $normalized): ?string
    {
        if ($storedName !== $normalized) {
            return null;
        }

        return $this->forNormalized($userId, $normalized);
    }

    // The user's own naming of the merchant, which outranks the bank's string.
    // Plaintext because rule evaluation joins on it.
    private function fromMerchants(int $userId, string $normalized): ?string
    {
        $row = $this->db->connection()->table('merchants')
            ->where('user_id', $userId)
            ->where('normalized_name', $normalized)
            ->first(['name']);

        if ($row === null) {
            return null;
        }

        return self::nameOrNull(self::toString($row->name));
    }

    // counterparty_name is encrypted at rest, and the codec answers '' for a
    // value this process holds no key for — so a sweep without the KEK falls
    // back to the key rather than writing ciphertext onto the review screen.
    private function fromTransactions(int $userId, string $normalized): ?string
    {
        $row = $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->where('counterparty_normalized', $normalized)
            ->whereNotNull('counterparty_name')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->first(['counterparty_name']);

        if ($row === null) {
            return null;
        }

        $decrypted = $this->codec->decryptValue(
            'transactions',
            'counterparty_name',
            self::toString($row->counterparty_name),
            $userId,
            ($this->session)(),
        );

        return self::nameOrNull($decrypted['value']);
    }

    private static function nameOrNull(string $name): ?string
    {
        $trimmed = trim($name);

        return $trimmed === '' ? null : $trimmed;
    }
}
