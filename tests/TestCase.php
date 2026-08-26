<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Vite;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;

/**
 * @link ../.docs/conventions/test-harness-isolation.md
 */
abstract class TestCase extends BaseTestCase
{
    // Typed via the App\Models\User alias CoreServiceProvider registers, so
    // framework consumers expecting the default Laravel namespace
    // (auth.providers.users.model, notification routing) resolve the same class.
    protected ?User $fixtureUser = null;

    // RefreshDatabase rolls the database back and leaves the disk alone, while
    // restarting user ids from 1 — so a test inherited the previous test's
    // encrypted sync keyring for "its" user and could not decrypt it. Deleting
    // keyrings between tests raced the writer's stage-then-rename; isolating won.
    private ?string $isolatedStorageRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        // UniqueLock calls $job->uniqueVia() at dispatch even on the sync queue
        // driver, so a ShouldBeUniqueUntilProcessing job would open a socket to a
        // Redis nobody provisions and hit a cache_locks table unit tests never
        // migrate. Both stores are redirected to the array driver.
        $this->app['config']->set('cache.stores.redis', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        $this->app['config']->set('cache.locks_store', 'array');

        $this->isolatedStorageRoot = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8))
            .DIRECTORY_SEPARATOR.'storage';

        // Without these, view compilation and the log channel have nowhere to write.
        foreach (['app', 'logs', 'framework/cache', 'framework/sessions', 'framework/views'] as $dir) {
            @mkdir($this->isolatedStorageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dir), 0777, true);
        }

        // Both path APIs have to agree: production resolves through
        // UserDataPathService (the env var), tests assert through storage_path().
        // Move only one and the assertion inspects a directory nothing wrote to.
        putenv('NATIVEPHP_STORAGE_PATH='.$this->isolatedStorageRoot);
        $this->app->useStoragePath($this->isolatedStorageRoot);
        $this->app['config']->set('view.compiled', $this->isolatedStorageRoot.'/framework/views');

        // Disk roots resolve from storage_path() when config LOADS, before
        // useStoragePath() above moves it, so Storage::disk('local') went on
        // writing to the real tree — one directory shared by every --parallel
        // worker, whose repeating staging paths overwrote each other mid-read.
        foreach (['local' => 'app/private', 'public' => 'app/public'] as $disk => $sub) {
            $this->app['config']->set(
                "filesystems.disks.{$disk}.root",
                $this->isolatedStorageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub),
            );
        }

        // Vite picks dev-server URLs over manifest URLs whenever `public/hot`
        // exists, which the running dev server writes — so with the desktop app
        // up, every rendered-HTML assertion saw http://[::1]:5174/… and failed.
        $this->app->make(Vite::class)->useHotFile(
            $this->isolatedStorageRoot.DIRECTORY_SEPARATOR.'vite-never-hot',
        );
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

    // A mis-set root must never let this walk reach a real tree.
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

    // `NL57ASNB0123456789` is baked into tests/fixtures/asn-sample-1.csv and is
    // looked up directly by EloquentAccountResolver — do not change it.
    // `ICS-CARD` and `PAYPAL` are the synthetic own-IBAN literals IcsPdfAdapter
    // and PaypalCsvAdapter emit; AccountResolver already scopes lookups by user.
    // $currency is a parameter, not a constant, because a fixture that can only
    // ever be euro cannot see a bug that only shows up when the reader is not:
    // the base-currency setting was inert for a whole release behind fixtures
    // where the hardcoded literal and the correct value were the same string.
    /**
     * @return array{user: User, account: Account, icsAccount: Account, paypalAccount: Account}
     */
    public function seedFixtureUserAndAccount(string $currency = Currency::Eur->value): array
    {
        $this->fixtureUser = User::query()->updateOrCreate(
            ['username' => 'fixture'],
            ['password' => 'fixture-password', 'period_start_day' => 1, 'base_currency' => $currency],
        );

        $account = Account::query()->updateOrCreate(
            ['iban' => 'NL57ASNB0123456789'],
            [
                'user_id' => $this->fixtureUser->id,
                'name' => 'ASN Fixture Account',
                'slug' => 'asn-fixture',
                'kind' => 'asn',
                'default_currency' => $currency,
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
                'default_currency' => $currency,
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
                'default_currency' => $currency,
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
