<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Scheduling\DailyLocalWindow;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Notifications\Internal\Enums\DeferredNotificationPass;
use Modules\Notifications\Internal\Support\DeferredNotificationPasses;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// A deferred mark waits out the same day the claim spent the pass on, so the
// two are one quantity. Written as two literals they were held together by a
// comment saying so, and a comment cannot fail when one of them moves.

function oneSpanUser(): User
{
    return User::query()->create([
        'username' => 'one-span-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

it('holds a deferred mark for exactly the span a day claim is held for', function (): void {
    // Both writes land as resolved seconds on the store, which is the only
    // layer that sees the number each side actually chose.
    $store = new class extends ArrayStore
    {
        /** @var list<mixed> */
        public array $secondsWritten = [];

        public function put($key, $value, $seconds): bool
        {
            $this->secondsWritten[] = $seconds;

            return parent::put($key, $value, $seconds);
        }
    };

    $cache = new Repository($store);

    $window = new DailyLocalWindow($cache, new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-08-29 09:15:00');
        }
    });

    expect($window->claim('one-span-probe', '09:15'))->toBeTrue();
    expect($store->secondsWritten)->toHaveCount(1);

    $user = oneSpanUser();
    $this->enablesEncryptionForUser($user);

    // A cold Store the app lock never unlocked is what an OS-scheduled process
    // holds, and it is the state deferIfKeyless() exists to answer.
    $passes = new DeferredNotificationPasses(
        $this->app,
        $cache,
        $this->app->make(EncryptionMigrationService::class),
        $this->app->make(SensitiveColumnCodec::class),
        SessionFactory::forSession(new Store('one-span-cold', new ArraySessionHandler(120))),
    );

    expect($passes->deferIfKeyless((int) $user->id, DeferredNotificationPass::BudgetNudges))->toBeTrue();
    expect($store->secondsWritten)->toHaveCount(2);
    expect($store->secondsWritten[1])->toBe($store->secondsWritten[0]);
});
