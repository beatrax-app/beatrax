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
     * A private storage root per test, so nothing on disk outlives its test.
     *
     * RefreshDatabase rolls back the database and leaves the disk alone. The
     * sync keyring is written to a file named for the user id, and
     * RefreshDatabase restarts those ids from 1 in every test — so a test
     * inherited the previous test's encrypted keyring for "its" user, could not
     * decrypt it with a session that had never held that key, and failed
     * depending only on what ran before it.
     *
     * Isolating beats purging. An earlier attempt deleted the keyrings between
     * tests and broke the writer, which stages a file and renames it into
     * place. An empty root of its own leaves nothing to delete and nothing to
     * race.
     *
     * Tests that sandbox the root themselves keep working: their beforeEach
     * runs after this and wins, and tearDown only removes what it created.
     */
    private ?string $isolatedStorageRoot = null;

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

        $this->isolatedStorageRoot = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8))
            .DIRECTORY_SEPARATOR.'storage';

        // The framework's own directories, or view compilation and the log
        // channel have nowhere to write.
        foreach (['app', 'logs', 'framework/cache', 'framework/sessions', 'framework/views'] as $dir) {
            @mkdir($this->isolatedStorageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dir), 0777, true);
        }

        // Both path APIs have to agree. Production code resolves through
        // UserDataPathService, which reads the env var; tests assert through
        // Laravel's storage_path(). Moving only one splits them, and the
        // assertion then looks at a directory the code never wrote to.
        putenv('NATIVEPHP_STORAGE_PATH='.$this->isolatedStorageRoot);
        $this->app->useStoragePath($this->isolatedStorageRoot);
        $this->app['config']->set('view.compiled', $this->isolatedStorageRoot.'/framework/views');
    }

    protected function tearDown(): void
    {
        putenv('NATIVEPHP_STORAGE_PATH');

        if (is_string($this->isolatedStorageRoot)) {
            self::removeTree(dirname($this->isolatedStorageRoot));
            $this->isolatedStorageRoot = null;
        }

        parent::tearDown();
    }

    // Refuses to walk anything outside the system temp directory, so a
    // mis-set root can never reach a real tree.
    private static function removeTree(string $path): void
    {
        $tmp = realpath(sys_get_temp_dir());
        $real = realpath($path);

        if ($tmp === false || $real === false || ! str_starts_with($real, $tmp.DIRECTORY_SEPARATOR)) {
            return;
        }

        foreach (scandir($real) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $real.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($child) && ! is_link($child)) {
                self::removeTree($child);

                continue;
            }

            @unlink($child);
        }

        @rmdir($real);
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
