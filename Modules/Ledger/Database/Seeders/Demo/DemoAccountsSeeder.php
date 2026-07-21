<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

// updateOrCreate keyed on (user_id, slug) keeps account IDs stable
// across runs, so transactions seeded against these accounts in a
// prior run still point at the same account_id on the next run.
final class DemoAccountsSeeder
{
    // Keyed by username, matching the user map DemoUsersSeeder
    // returns.
    /** @var array<string, list<array{name: string, slug: string, kind: string, iban: string, default_currency: string, starting_balance_minor: int}>> */
    private const ACCOUNTS = [
        'demo-1@beatrax.local' => [
            [
                'name' => 'ASN Bank',
                'slug' => 'asn-demo-1',
                'kind' => 'asn',
                'iban' => 'NL57ASNB0123456789',
                'default_currency' => 'EUR',
                'starting_balance_minor' => 285000,
            ],
            [
                'name' => 'ICS Card',
                'slug' => 'ics-demo-1',
                // Production code (ReconcilePage, ThisPeriodAtAGlanceQuery,
                // IcsSettlementResolver, Chains backpopulate) keys ICS
                // behaviour on 'ics_card'; 'ics' leaves the demo's ICS
                // reconcile pre-fill / chains features dormant.
                'kind' => 'ics_card',
                'iban' => 'ICS-DEMO-1-CARD',
                'default_currency' => 'EUR',
                'starting_balance_minor' => -15000,
            ],
            [
                'name' => 'PayPal',
                'slug' => 'paypal-demo-1',
                'kind' => 'paypal',
                'iban' => 'PAYPAL-DEMO-1',
                'default_currency' => 'EUR',
                'starting_balance_minor' => 4200,
            ],
        ],
        'demo-2@beatrax.local' => [
            [
                'name' => 'ASN Bank',
                'slug' => 'asn-demo-2',
                'kind' => 'asn',
                'iban' => 'NL09ASNB0987654321',
                'default_currency' => 'EUR',
                'starting_balance_minor' => 168000,
            ],
            [
                'name' => 'PayPal',
                'slug' => 'paypal-demo-2',
                'kind' => 'paypal',
                'iban' => 'PAYPAL-DEMO-2',
                'default_currency' => 'EUR',
                'starting_balance_minor' => 0,
            ],
        ],
    ];

    // Returns the materialised Account models keyed first by username,
    // then by slug, so downstream seeders can address an exact account
    // without re-querying the database.
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
