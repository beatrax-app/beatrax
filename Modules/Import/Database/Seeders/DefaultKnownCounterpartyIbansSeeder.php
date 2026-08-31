<?php

declare(strict_types=1);

namespace Modules\Import\Database\Seeders;

use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Public\Enums\AccountKind;

final class DefaultKnownCounterpartyIbansSeeder
{
    // The note becomes the bank counterparty's display name, so it holds the
    // registered name of the institution behind the IBAN and the bank it sits
    // at — proper nouns and punctuation, no word of it copy. An English
    // connective here was a sentence a Dutch reader would have read back.
    /**
     * @var list<array{real_iban: string, target_account_kind: string, notes: string}>
     */
    private const ALIASES = [
        [
            'real_iban' => 'LU89751000135104200E',
            'target_account_kind' => AccountKind::Paypal->value,
            'notes' => 'PayPal (Sàrl et Cie, SCA) — Luxembourg',
        ],
        [
            'real_iban' => 'NL08ABNA0526650664',
            'target_account_kind' => AccountKind::IcsCard->value,
            'notes' => 'International Card Services BV — ABN AMRO',
        ],
    ];

    public function run(User $user): void
    {
        foreach (self::ALIASES as $alias) {
            KnownCounterpartyIban::withoutGlobalScopes()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'real_iban' => $alias['real_iban'],
                ],
                [
                    'target_account_kind' => $alias['target_account_kind'],
                    'notes' => $alias['notes'],
                ],
            );
        }
    }
}
