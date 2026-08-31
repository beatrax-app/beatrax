<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;

// Which account a PayPal funding row names, and the key that stands for that
// account wherever the link records what it matched. All three arms of the
// resolver name the funding ACCOUNT here: the arm that named the counterparty
// instead gave one merchant on one account two signatures that never met.
/**
 * @link ../../../.docs/architecture/chain-resolution.md
 */
final readonly class PaypalFundingSignatureKey
{
    use CoercesScalars;

    // An account with no IBAN would answer to the empty key, which names
    // nothing, so its id stands in. The key is written to
    // evidence.matched_iban as well, because that is where
    // CounterpartyKeyBackfill's re-signing sweep looks for it first.
    private const string ACCOUNT_SIGNATURE_PREFIX = 'account=';

    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function accountIdForIban(string $iban, User $user): ?int
    {
        $row = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', $iban)
            ->first(['id']);

        if ($row === null) {
            return null;
        }

        return self::toInt($row->id);
    }

    public function forAccount(int $accountId, User $user): string
    {
        $iban = $this->ibanForAccountId($accountId, $user) ?? '';

        return $iban !== '' ? $iban : self::ACCOUNT_SIGNATURE_PREFIX.$accountId;
    }

    private function ibanForAccountId(int $accountId, User $user): ?string
    {
        $row = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->first(['iban']);

        if ($row === null) {
            return null;
        }

        return self::toString($row->iban);
    }
}
