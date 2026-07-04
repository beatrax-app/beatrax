<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

/**
 * Idempotent demo-account seeder. Materialises the five accounts that
 * back the demo dataset:
 *
 *   demo-1@beatrax.local
 *     - ASN bank   (NL57ASNB0123456789)
 *     - ICS card   (1234 56XX XXXX 7890 — stored as the card's
 *                   reference IBAN-shaped slot since the schema only
 *                   knows IBANs; the slug 'ics-demo-1' is the routing
 *                   key the UI uses)
 *     - PayPal     (PAYPAL-DEMO-1 — IBAN slot carries the surrogate
 *                   identifier the import pipeline stamps on receipt-
 *                   only sources)
 *
 *   demo-2@beatrax.local
 *     - ASN bank   (NL09ASNB0987654321)
 *     - PayPal     (PAYPAL-DEMO-2)
 *
 * Each account gets a sensible `starting_balance_minor` (a few
 * thousand euros for bank / card accounts, zero for the PayPal wallet)
 * so the dashboard "running balance" renders as a believable curve
 * rather than starting at zero on day one of the demo window.
 *
 * Idempotency: `updateOrCreate` keyed on `(user_id, slug)` keeps the
 * primary keys stable across runs so transactions seeded against the
 * accounts in a prior run still point at the same `account_id` on the
 * next run.
 */
final class DemoAccountsSeeder
{
    /**
     * Per-user account catalog. The outer key is the username so the
     * seeder can look the right slate up against the user map the
     * preceding DemoUsersSeeder returned.
     *
     * @var array<string, list<array{name: string, slug: string, kind: string, iban: string, default_currency: string, starting_balance_minor: int}>>
     */
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
                // U-2: production code (ReconcilePage, ThisPeriodAtAGlanceQuery,
                // IcsSettlementResolver, Chains backpopulate) keys ICS behaviour
                // on 'ics_card'. 'ics' left the demo's ICS reconcile pre-fill /
                // this-period / chains features dormant. Align to 'ics_card'.
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

    /**
     * Seed every demo account for every user in the supplied map and
     * return the materialised Account models keyed first by username,
     * then by slug. The double-keyed shape lets downstream seeders
     * address an exact account (e.g. `$accounts['demo-1@beatrax.local']['asn-demo-1']`)
     * without re-querying the database.
     *
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
