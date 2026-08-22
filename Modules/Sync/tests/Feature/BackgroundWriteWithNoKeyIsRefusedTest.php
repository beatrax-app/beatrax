<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Budgets\Public\Events\BudgetThresholdCrossed;
use Modules\Core\Models\User;
use Modules\Sync\Public\Exceptions\SensitiveColumnKeyUnavailableException;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */

// A live desktop held a notifications table with thirteen ciphertext rows and
// six plaintext ones in the same column, all one user, the plaintext written
// fifteen minutes AFTER encryption was enabled. The writer was
// EmitBudgetNudgesJob on the queue worker: no HTTP session, so no app-lock key,
// so the codec found no epoch and returned the title and body unchanged. The
// settings screen said "Data encrypted at rest: On" throughout.
//
// The precondition that matters is the ABSENCE of a session key, and a fixture
// that supplies one tests nothing. Both arms below withhold it: the first
// through the bare Store a queue worker actually resolves, the second by
// withholding the key from the container's own session and driving the real
// event the job dispatches.

const BWNK_NUDGE_TITLE = 'Budget nearly spent';

function bwnkUser(): User
{
    return User::query()->create([
        'username' => 'bwnk-user-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('bwnk-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// What a queue worker resolves for Session::class: a real store that was never
// unlocked, holding no app-lock key and not carrying the locked flag either.
function bwnkKeylessSession(): Session
{
    return new Store('bwnk-worker-session', new ArraySessionHandler(120));
}

it('refuses a background write to a registered column when encryption is on and no key is held', function (): void {
    $user = bwnkUser();
    $this->enablesEncryptionForUser($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $attrs = [
        'id' => 'bwnk-notification',
        'user_id' => $user->id,
        'title' => BWNK_NUDGE_TITLE,
        'body' => 'You have spent 92% of Groceries.',
        'trigger_type' => 'budget_nudge',
    ];

    expect(fn (): array => $codec->encryptAttrs('notifications', $attrs, (int) $user->id, bwnkKeylessSession()))
        ->toThrow(SensitiveColumnKeyUnavailableException::class);

    expect(fn (): string => $codec->encryptValue('transactions', 'note', 'ALBERT HEIJN 1234', (int) $user->id, bwnkKeylessSession()))
        ->toThrow(SensitiveColumnKeyUnavailableException::class);
});

// The other half of the same rule. A user who never enabled encryption has no
// `current_epoch`, every other column of theirs is plaintext too, and refusing
// their writes would break an install that is behaving exactly as designed —
// which is the state the live database's second user was in.
it('still passes plaintext through for a user who never enabled encryption', function (): void {
    $user = bwnkUser();

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $attrs = $codec->encryptAttrs(
        'notifications',
        ['title' => BWNK_NUDGE_TITLE, 'trigger_type' => 'forecast_shortfall'],
        (int) $user->id,
        bwnkKeylessSession(),
    );

    expect($attrs['title'])->toBe(BWNK_NUDGE_TITLE);
    expect($codec->canSeal((int) $user->id, bwnkKeylessSession()))->toBeFalse();
});

// An $attrs naming no registered column is not a sensitive write at all, so
// refusing it would stop a caller that this codec has no opinion about.
it('does not refuse a write that names no registered column', function (): void {
    $user = bwnkUser();
    $this->enablesEncryptionForUser($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $attrs = $codec->encryptAttrs(
        'notifications',
        ['id' => 'bwnk-skeleton', 'user_id' => $user->id, 'state' => 'open'],
        (int) $user->id,
        bwnkKeylessSession(),
    );

    expect($attrs['state'])->toBe('open');
});

// The end-to-end shape from the log, driven through the same public event
// EmitBudgetNudgesJob dispatches. PersistBudgetNudge catches Throwable and
// logs, so the refusal costs a nudge rather than the job — and the row that
// used to land in the clear does not land at all.
it('writes no notification row at all when the nudge listener cannot seal it', function (): void {
    $user = bwnkUser();
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);

    AppLockTestHarness::lock($session);

    $this->app->make(Dispatcher::class)->dispatch(new BudgetThresholdCrossed(
        userId: (int) $user->id,
        categoryId: 1,
        categoryName: 'Groceries',
        period: '2026-08-01',
        thresholdPercent: 90,
        spentMinor: 46000,
        budgetMinor: 50000,
        currency: 'EUR',
        categorySlug: 'groceries',
    ));

    $titles = $this->app->make(DatabaseManager::class)->connection()
        ->table('notifications')->where('user_id', $user->id)->pluck('title')->all();

    // Asserted before the count, so a regression names the plaintext it wrote
    // rather than reporting a size — the leak is the readable title, and an
    // empty table is only how this codec is allowed to avoid it.
    expect($titles)->not->toContain(BWNK_NUDGE_TITLE);
    expect($titles)->toHaveCount(0);
});

// The refusal has to be reachable in the state a locked desktop is normally
// in, which is the whole reason the silent path went unnoticed: canSeal() is
// what a caller with somewhere else to go asks first.
it('reports that it cannot seal, rather than answering by writing', function (): void {
    $user = bwnkUser();
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    expect($codec->canSeal((int) $user->id, $session))->toBeTrue();

    AppLockTestHarness::lock($session);

    expect($codec->canSeal((int) $user->id, $session))->toBeFalse();
});
