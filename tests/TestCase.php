<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Modules\Ledger\Models\Account;

/**
 * Root TestCase. Module-local TestCases extend this one.
 *
 * `$fixtureUser` and `seedFixtureUserAndAccount()` exist here so the
 * cross-module IdempotencyContractTest and the AsnCsvImportTest in the
 * Import module share a single canonical seeded user + ASN account row.
 * The helper resolves against the `App\Models\User` class alias that
 * `CoreServiceProvider` registers for `Modules\Core\Models\User`.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * The canonical fixture user resolved during seedFixtureUserAndAccount().
     *
     * Typed via the App\Models\User class alias for stability — the alias is
     * registered in CoreServiceProvider so framework consumers expecting the
     * default Laravel namespace (auth.providers.users.model, notification
     * routing) resolve the same class as the module-namespaced model.
     */
    protected ?User $fixtureUser = null;

    /**
     * Redirect the `redis` cache store to the array driver during
     * tests so any `Cache::driver('redis')` call (e.g. inside
     * `ResolveChainLinksJob::uniqueVia()`) returns an in-memory
     * Repository instead of trying to open a TCP socket to a Redis
     * server that the test harness does not provision. Without this
     * override, every `ConfirmImport` feature test would fail with
     * `Connection refused [tcp://127.0.0.1:6379]` because Laravel's
     * UniqueLock machinery calls `$job->uniqueVia()` unconditionally
     * during dispatch — including for the `sync` queue driver.
     *
     * Tests that explicitly need a real Redis (e.g.
     * `HorizonBootsTest`) check the connection up front and skip
     * when Redis is not reachable; this override does not interfere
     * with those because they ignore the cache store and talk to
     * Redis directly via the predis client.
     *
     * The shipped `cache.locks_store` default is `database`, whose lock
     * repository writes to the `cache_locks` table. Unit tests do not run
     * migrations, so dispatching a `ShouldBeUniqueUntilProcessing` job —
     * which makes Laravel's `UniqueLock` machinery acquire a lock via
     * `uniqueVia()` — would fail with `no such table: cache_locks`. The
     * override below routes queue-uniqueness locks to the in-memory
     * `array` store. Tests that exercise the real database lock store
     * (e.g. `DatabaseQueueConcurrencyTest`) set `cache.locks_store` back
     * to `database` in their own `beforeEach()`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('cache.stores.redis', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        $this->app['config']->set('cache.locks_store', 'array');

    }

    /**
     * Seeds the canonical fixture User + ASN Account + ICS Account +
     * PayPal Account so the contract tests and the per-module
     * *ImportTest files can resolve their respective own-IBANs without
     * falling through to the unknown-IBAN wizard step.
     *
     * IBAN `NL57ASNB0123456789` is the load-bearing anonymisation value
     * baked into tests/fixtures/asn-sample-1.csv. Do NOT change this
     * literal — `EloquentAccountResolver` looks it up directly.
     *
     * IBAN `ICS-CARD` is the synthetic own-IBAN literal the
     * `IcsPdfAdapter` emits for every parsed ICS PDF row. The
     * AccountResolver scopes lookups by `user_id` already, so a single
     * instance-wide literal is unambiguous.
     *
     * IBAN `PAYPAL` is the synthetic own-IBAN literal the
     * `PaypalCsvAdapter` emits for every parsed PayPal Activity
     * Download row. Same scoping shape as `ICS-CARD`.
     *
     * @return array{user: User, account: Account, icsAccount: Account, paypalAccount: Account}
     */
    public function seedFixtureUserAndAccount(): array
    {
        $this->fixtureUser = User::query()->updateOrCreate(
            ['username' => 'fixture'],
            ['password' => 'fixture-password', 'period_start_day' => 1],
        );

        $account = Account::query()->updateOrCreate(
            ['iban' => 'NL57ASNB0123456789'],
            [
                'user_id' => $this->fixtureUser->id,
                'name' => 'ASN Fixture Account',
                'slug' => 'asn-fixture',
                'kind' => 'asn',
                'default_currency' => 'EUR',
            ],
        );

        $icsAccount = Account::query()->updateOrCreate(
            [
                'user_id' => $this->fixtureUser->id,
                'iban' => 'ICS-CARD',
            ],
            [
                'name' => 'ICS card (fixture)',
                'slug' => 'ics-card-fixture',
                'kind' => 'ics_card',
                'default_currency' => 'EUR',
            ],
        );

        $paypalAccount = Account::query()->updateOrCreate(
            [
                'user_id' => $this->fixtureUser->id,
                'iban' => 'PAYPAL',
            ],
            [
                'name' => 'PayPal (fixture)',
                'slug' => 'paypal-fixture',
                'kind' => 'paypal',
                'default_currency' => 'EUR',
            ],
        );

        return [
            'user' => $this->fixtureUser,
            'account' => $account,
            'icsAccount' => $icsAccount,
            'paypalAccount' => $paypalAccount,
        ];
    }
}
