<?php

declare(strict_types=1);

namespace Modules\Import\Database\Seeders;

use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;

/**
 * Per-user idempotent seeder for the two real-world institution IBANs
 * that appear on the ASN side of every cross-account hop in this
 * project's domain:
 *
 *  - `LU89751000135104200E` → PayPal SARL et Cie SCA, Luxembourg →
 *    resolves to the user's `paypal` account (synthetic IBAN `PAYPAL`).
 *  - `NL08ABNA0526650664`   → International Card Services BV at ABN
 *    AMRO → resolves to the user's `ics_card` account (synthetic
 *    IBAN `ICS-CARD`).
 *
 * Uses `firstOrCreate` keyed by (user_id, real_iban) so a re-run
 * produces zero new rows AND preserves any custom `notes` value the
 * user may have edited on an existing row. `updateOrCreate` would
 * reset notes back to the seed default, which is not the intent.
 */
final class DefaultKnownCounterpartyIbansSeeder
{
    /**
     * @var list<array{real_iban: string, target_account_kind: string, notes: string}>
     */
    private const ALIASES = [
        [
            'real_iban' => 'LU89751000135104200E',
            'target_account_kind' => 'paypal',
            'notes' => 'PayPal SARL et Cie SCA, Luxembourg.',
        ],
        [
            'real_iban' => 'NL08ABNA0526650664',
            'target_account_kind' => 'ics_card',
            'notes' => 'International Card Services BV at ABN AMRO.',
        ],
    ];

    public function run(User $user): void
    {
        foreach (self::ALIASES as $alias) {
            KnownCounterpartyIban::query()->firstOrCreate(
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
