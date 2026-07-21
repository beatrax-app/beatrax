<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkRewrapContract;
use Modules\Sync\Internal\Crypto\RewrapGdkOnPassphraseChange;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/*
 * Security-review regression: a GDK keyring re-wrap failure after an app-lock
 * passphrase change must surface a CRITICAL in-app SystemAlert (not just a log
 * line) so a single-device user can recover, while preserving the handler's
 * never-throw guarantee — the passphrase change is already committed when this
 * runs, so neither the re-wrap nor the alert write may propagate.
 */

function rewrapGdkTestUser(): User
{
    return User::query()->create([
        'username' => 'rewrap-gdk-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('rewrap-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/** A GdkRewrapContract fake whose rewrap() always throws. */
function throwingGdkRewrap(): GdkRewrapContract
{
    return new class implements GdkRewrapContract
    {
        public function rewrap(int $userId, string $oldKek, string $newKek): void
        {
            throw new RuntimeException('keyring re-wrap exploded');
        }
    };
}

/** A GdkRewrapContract fake whose rewrap() is a clean no-op (success). */
function noopGdkRewrap(): GdkRewrapContract
{
    return new class implements GdkRewrapContract
    {
        public function rewrap(int $userId, string $oldKek, string $newKek): void
        {
            // success — nothing to do
        }
    };
}

it('does not throw and writes a critical SystemAlert when the rewrap fails', function (): void {
    $user = rewrapGdkTestUser();
    $userId = (int) $user->id;

    $handler = new RewrapGdkOnPassphraseChange(throwingGdkRewrap(), new NullLogger);

    expect(fn () => $handler->handle(new AppLockPassphraseChanged($userId, 'old', 'new')))
        ->not->toThrow(Throwable::class);

    $alert = SystemAlert::query()->where('kind', 'sync.gdk.rewrap_failed')->first();
    expect($alert)->not->toBeNull();
    expect($alert->severity)->toBe('critical');
    expect($alert->user_id)->toBe($userId);
});

it('does not write a SystemAlert when the rewrap succeeds', function (): void {
    $user = rewrapGdkTestUser();
    $userId = (int) $user->id;

    $handler = new RewrapGdkOnPassphraseChange(noopGdkRewrap(), new NullLogger);

    $handler->handle(new AppLockPassphraseChanged($userId, 'old', 'new'));

    expect(SystemAlert::query()->where('kind', 'sync.gdk.rewrap_failed')->count())->toBe(0);
});

it('never propagates even when the SystemAlert write itself fails', function (): void {
    $user = rewrapGdkTestUser();
    $userId = (int) $user->id;

    // Drop the alert store so SystemAlert::create() throws inside the catch
    // block — the inner last-resort no-op must swallow it and handle() must
    // still return cleanly (never-throw guarantee, incl. the alert write).
    Schema::drop('system_alerts');

    $handler = new RewrapGdkOnPassphraseChange(throwingGdkRewrap(), new NullLogger);

    expect(fn () => $handler->handle(new AppLockPassphraseChanged($userId, 'old', 'new')))
        ->not->toThrow(Throwable::class);
});

it('preserves the existing log->error call on rewrap failure', function (): void {
    $user = rewrapGdkTestUser();
    $userId = (int) $user->id;

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')
        ->once()
        ->with('RewrapGdkOnPassphraseChange: GDK re-wrap failed', Mockery::type('array'));

    $handler = new RewrapGdkOnPassphraseChange(throwingGdkRewrap(), $logger);

    $handler->handle(new AppLockPassphraseChanged($userId, 'old', 'new'));
});
