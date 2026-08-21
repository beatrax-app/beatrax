<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;

// updateOrCreate on (user_id, slug) keeps account ids stable across runs, so
// transactions seeded in a prior run still point at the same account_id.
final class DemoAccountsSeeder
{
    // Keyed by username, matching the map DemoUsersSeeder returns.
    /** @var array<string, list<array{name: string, slug: string, kind: string, iban: string, default_currency: string, starting_balance_minor: int}>> */
    private const ACCOUNTS = [
        'demo-1@beatrax.local' => [
            [
                'name' => 'ASN Bank',
                'slug' => 'asn-demo-1',
                'kind' => AccountKind::Bank->value,
                'iban' => 'NL57ASNB0123456789',
                'default_currency' => 'EUR',
                'starting_balance_minor' => 285000,
            ],
            [
                'name' => 'ICS Card',
                'slug' => 'ics-demo-1',
                // Production code keys ICS behaviour on 'ics_card'; 'ics' would
                // leave the demo's reconcile pre-fill and chains dormant.
                'kind' => AccountKind::IcsCard->value,
                'iban' => 'ICS-DEMO-1-CARD',
                'default_currency' => 'EUR',
                'starting_balance_minor' => -15000,
            ],
            [
                'name' => 'PayPal',
                'slug' => 'paypal-demo-1',
                'kind' => AccountKind::Paypal->value,
                'iban' => 'PAYPAL-DEMO-1',
                'default_currency' => 'EUR',
                'starting_balance_minor' => 4200,
            ],
        ],
        'demo-2@beatrax.local' => [
            [
                'name' => 'ASN Bank',
                'slug' => 'asn-demo-2',
                'kind' => AccountKind::Bank->value,
                'iban' => 'NL09ASNB0987654321',
                'default_currency' => 'EUR',
                'starting_balance_minor' => 168000,
            ],
            [
                'name' => 'PayPal',
                'slug' => 'paypal-demo-2',
                'kind' => AccountKind::Paypal->value,
                'iban' => 'PAYPAL-DEMO-2',
                'default_currency' => 'EUR',
                'starting_balance_minor' => 0,
            ],
        ],
    ];

    // Keyed username then slug, so downstream seeders need no re-query.
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
