<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

// updateOrCreate on (user_id, slug) keeps account ids stable across runs, so
// transactions seeded in a prior run still point at the same account_id.
final class DemoAccountsSeeder
{
    /** @var array<string, list<array{name: string, slug: string, kind: string, iban: string, default_currency: string, starting_balance_minor: int}>> */
    private const ACCOUNTS = [
        'demo-1' => [
            [
                'name' => 'ASN Bank',
                'slug' => 'asn-demo-1',
                'kind' => AccountKind::Bank->value,
                'iban' => 'NL57ASNB0123456789',
                'default_currency' => Currency::Eur->value,
                'starting_balance_minor' => 285000,
            ],
            [
                'name' => 'ICS Card',
                'slug' => 'ics-demo-1',
                // Production code keys ICS behaviour on 'ics_card'; 'ics' would
                // leave the demo's reconcile pre-fill and chains dormant.
                'kind' => AccountKind::IcsCard->value,
                'iban' => 'ICS-DEMO-1-CARD',
                'default_currency' => Currency::Eur->value,
                'starting_balance_minor' => -15000,
            ],
            [
                'name' => 'PayPal',
                'slug' => 'paypal-demo-1',
                'kind' => AccountKind::Paypal->value,
                'iban' => 'PAYPAL-DEMO-1',
                'default_currency' => Currency::Eur->value,
                'starting_balance_minor' => 4200,
            ],
            // Zero-decimal on purpose. Every other account here is 1/100, so a
            // dataset without this one cannot tell a correct scale from a
            // hardcoded division by 100: the balance, the split editor and the
            // reconcile field all read identically either way.
            [
                'name' => 'Japan Trip Card',
                'slug' => 'jpy-demo-1',
                'kind' => AccountKind::IcsCard->value,
                'iban' => 'ICS-DEMO-1-JPY',
                'default_currency' => Currency::Jpy->value,
                'starting_balance_minor' => 120000,
            ],
            // A card holds no allocatable balance, so with the trip card as the
            // only zero-decimal account no pot, pot-funded goal or cash entry
            // could be denominated without a minor unit. It is also the account
            // the cash book takes the scale of its amount field from.
            [
                'name' => 'Japan Trip Cash',
                'slug' => 'jpy-cash-demo-1',
                'kind' => AccountKind::Cash->value,
                'iban' => 'CASH-DEMO-1-JPY',
                'default_currency' => Currency::Jpy->value,
                'starting_balance_minor' => 600000,
            ],
        ],
        'demo-2' => [
            [
                'name' => 'ASN Bank',
                'slug' => 'asn-demo-2',
                'kind' => AccountKind::Bank->value,
                'iban' => 'NL09ASNB0987654321',
                'default_currency' => Currency::Eur->value,
                'starting_balance_minor' => 168000,
            ],
            [
                'name' => 'PayPal',
                'slug' => 'paypal-demo-2',
                'kind' => AccountKind::Paypal->value,
                'iban' => 'PAYPAL-DEMO-2',
                'default_currency' => Currency::Eur->value,
                'starting_balance_minor' => 0,
            ],
        ],
    ];

    /**
     * @param  array<string, User>  $users
     * @return array<string, array<string, Account>>
     */
    public function run(array $users): array
    {
        $byUserSlug = [];

        foreach (self::ACCOUNTS as $username => $rows) {
            if (! isset($users[$username])) {
                continue;
            }
            $user = $users[$username];

            $byUserSlug[$username] = [];

            foreach ($rows as $row) {
                /** @var Account $account */
                $account = Account::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'slug' => $row['slug'],
                    ],
                    [
                        'name' => $row['name'],
                        'kind' => $row['kind'],
                        'iban' => $row['iban'],
                        'default_currency' => $row['default_currency'],
                        'starting_balance_minor' => $row['starting_balance_minor'],
                    ],
                );

                $byUserSlug[$username][$row['slug']] = $account;
            }
        }

        return $byUserSlug;
    }
}
