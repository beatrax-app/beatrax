<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Notifications\Internal\Enums\DeferredNotificationPass;
use Modules\Notifications\Internal\Enums\NotificationWriteOutcome;
use Modules\Notifications\Internal\Http\Middleware\RunDeferredNotificationPasses;
use Modules\Notifications\Internal\Support\DeferredNotificationPasses;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// A scheduled pass on a phone is a cold-started process with its own empty
// session, so it never holds the app-lock key. SensitiveColumnCodec is right to
// refuse the write; the question this file asks is what happens next, and until
// the deferral seam landed the answer was nothing, forever.

function dnpUser(): User
{
    return User::query()->create([
        'username' => 'dnp-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function dnpOverspentGroceries(User $user): void
{
    app(DatabaseManager::class)->connection()->table('users')->where('id', $user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->startOfMonth(),
    ]);

    $category = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'dnp-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'DNP ASN',
        'slug' => 'dnp-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00DNP'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/dnp-'.bin2hex(random_bytes(4)).'.xml',
        'sha256' => hash('sha256', 'dnp-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    app(EnvelopeWriter::class)->setAssigned($user, $category->id, CarbonImmutable::now()->startOfMonth(), 10000);

    Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => -9500,
        'currency' => 'EUR',
        'settled_amount_minor' => -9500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'DnpMarkt',
        'counterparty_normalized' => 'dnpmarkt',
        'normalization_version' => 1,
        'category_id' => $category->id,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 91,
        'fingerprint' => str_pad('dnp91', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

// What Android WorkManager and the queue worker both resolve for Session::class:
// a real store that was never unlocked. Swapped into the container rather than
// handed to one collaborator, because `session.store` is a singleton and every
// consumer under the pass has to see the same empty one.
function dnpRunKeyless(callable $pass): void
{
    $unlocked = app('session.store');
    app()->instance('session.store', new Store('dnp-cold-process', new ArraySessionHandler(120)));

    try {
        app(SuppressionEvaluator::class)->suppressDelivery($pass);
    } finally {
        app()->instance('session.store', $unlocked);
    }
}

function dnpNotificationCount(int $userId): int
{
    return app(DatabaseManager::class)->connection()
        ->table('notifications')->where('user_id', $userId)->count();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('records the pass as outstanding instead of losing the nudge', function (): void {
    $user = dnpUser();
    $this->enablesEncryptionForUser($user);
    dnpOverspentGroceries($user);

    dnpRunKeyless(static fn () => Artisan::call('budgets:emit-nudges'));

    expect(dnpNotificationCount((int) $user->id))->toBe(0);

    /** @var DeferredNotificationPasses $passes */
    $passes = app(DeferredNotificationPasses::class);

    expect($passes->outstandingFor((int) $user->id))->toContain(DeferredNotificationPass::BudgetNudges);
});

it('delivers the nudge on the next request that holds a key', function (): void {
    $user = dnpUser();
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    dnpOverspentGroceries($user);

    dnpRunKeyless(static fn () => Artisan::call('budgets:emit-nudges'));
    expect(dnpNotificationCount((int) $user->id))->toBe(0);

    app(SuppressionEvaluator::class)->suppressDelivery(function () use ($user, $session): void {
        app(DeferredNotificationPasses::class)->runOutstanding((int) $user->id, $session);
    });

    expect(dnpNotificationCount((int) $user->id))->toBe(1);
    expect(app(DeferredNotificationPasses::class)->outstandingFor((int) $user->id))->toBe([]);
});

it('seals the recovered nudge rather than writing it in the clear', function (): void {
    $user = dnpUser();
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    dnpOverspentGroceries($user);

    dnpRunKeyless(static fn () => Artisan::call('budgets:emit-nudges'));

    app(SuppressionEvaluator::class)->suppressDelivery(function () use ($user, $session): void {
        app(DeferredNotificationPasses::class)->runOutstanding((int) $user->id, $session);
    });

    /** @var string $stored */
    $stored = app(DatabaseManager::class)->connection()
        ->table('notifications')->where('user_id', $user->id)->value('title');

    expect($stored)->not->toBe('Budget nearly spent');

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    expect($codec->decryptValue('notifications', 'title', $stored, (int) $user->id, $session))
        ->toBe(['value' => 'Budget nearly spent', 'decrypted' => true]);
});

// The pass that CAN seal must not start recording deferrals, or an install with
// no app lock would replay work the scheduler already did.
it('records nothing for a user who never enabled encryption', function (): void {
    $user = dnpUser();
    dnpOverspentGroceries($user);

    dnpRunKeyless(static fn () => Artisan::call('budgets:emit-nudges'));

    expect(dnpNotificationCount((int) $user->id))->toBe(1);
    expect(app(DeferredNotificationPasses::class)->outstandingFor((int) $user->id))->toBe([]);
});

// The daily pass consumes its once-a-day window even when it can seal nothing,
// which is why the mark is what carries it and not a second claim: the reader
// unlocking at 09:20 is served by the per-user replay, not by the command.
it('delivers the daily triggers the claimed window would otherwise have swallowed', function (): void {
    $user = dnpUser();
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);

    dnpRunKeyless(static fn () => Artisan::call('notifications:daily-triggers'));

    expect(dnpNotificationCount((int) $user->id))->toBe(0);
    expect(app(DeferredNotificationPasses::class)->outstandingFor((int) $user->id))
        ->toContain(DeferredNotificationPass::DailyTriggers);

    app(SuppressionEvaluator::class)->suppressDelivery(function () use ($user, $session): void {
        app(DeferredNotificationPasses::class)->runOutstanding((int) $user->id, $session);
    });

    expect(dnpNotificationCount((int) $user->id))->toBeGreaterThan(0);
    expect(app(DeferredNotificationPasses::class)->outstandingFor((int) $user->id))->toBe([]);
});

it('tells the writer caller the row was withheld, not written and not duplicated', function (): void {
    $user = dnpUser();
    $this->enablesEncryptionForUser($user);

    $draft = new NotificationDraft(
        userId: (int) $user->id,
        triggerType: NotificationTrigger::BudgetNudge,
        subjectKey: 'envelope-1',
        occurrence: '2026-07',
        title: 'Budget nearly spent',
        body: 'Groceries is at 92%.',
    );

    $written = app(NotificationWriter::class)->write($draft);
    expect($written->outcome)->toBe(NotificationWriteOutcome::Written);
    expect($written->landed())->toBeTrue();

    $duplicate = app(NotificationWriter::class)->write($draft);
    expect($duplicate->outcome)->toBe(NotificationWriteOutcome::Duplicate);

    $deferred = null;
    dnpRunKeyless(function () use (&$deferred): void {
        $deferred = app(NotificationWriter::class)->write(new NotificationDraft(
            userId: (int) User::query()->value('id'),
            triggerType: NotificationTrigger::BudgetNudge,
            subjectKey: 'envelope-2',
            occurrence: '2026-07',
            title: 'Budget nearly spent',
            body: 'Fuel is at 95%.',
        ));
    });

    expect($deferred?->outcome)->toBe(NotificationWriteOutcome::Deferred);
    expect($deferred?->landed())->toBeFalse();

    // The id is derived, not allocated, so a withheld row still names itself
    // and a caller can ask later whether the content it was denied arrived.
    expect($deferred?->id)->toBe(app(DeterministicKeyDeriver::class)
        ->derive((int) $user->id, NotificationTrigger::BudgetNudge, 'envelope-2', '2026-07'));

    expect(dnpNotificationCount((int) $user->id))->toBe(1);
});

// The seam end to end, through a real request rather than a direct call: the
// middleware is on the `web` group of both roots and runs at terminate, so
// opening any page is what pays for the pass the scheduler could not run.
it('re-derives the nudge from an ordinary authenticated request', function (): void {
    $user = dnpUser();
    $this->enablesEncryptionForUser($user);
    dnpOverspentGroceries($user);

    dnpRunKeyless(static fn () => Artisan::call('budgets:emit-nudges'));
    expect(dnpNotificationCount((int) $user->id))->toBe(0);

    $this->actingAs($user);
    app(SuppressionEvaluator::class)->suppressDelivery(function (): void {
        $this->get('/notifications')->assertOk();
    });

    expect(dnpNotificationCount((int) $user->id))->toBe(1);
});

// The other side of the same request, so the middleware cannot quietly stop
// being what does this: with it out of the stack the page still renders and the
// nudge is still nowhere, which is the state the device was measured in.
it('leaves the nudge underived when that middleware is out of the stack', function (): void {
    $user = dnpUser();
    $this->enablesEncryptionForUser($user);
    dnpOverspentGroceries($user);

    dnpRunKeyless(static fn () => Artisan::call('budgets:emit-nudges'));

    $this->actingAs($user);
    $this->withoutMiddleware(RunDeferredNotificationPasses::class);

    app(SuppressionEvaluator::class)->suppressDelivery(function (): void {
        $this->get('/notifications')->assertOk();
    });

    expect(dnpNotificationCount((int) $user->id))->toBe(0);
    expect(app(DeferredNotificationPasses::class)->outstandingFor((int) $user->id))
        ->toContain(DeferredNotificationPass::BudgetNudges);
});

// The deferral is the normal outcome on an install with encryption at rest --
// every OS-scheduled process is a keyless one -- so a pass that emitted for
// nobody has to say so. Returning SUCCESS in silence reads at the console, and
// to the phone's background runner, exactly like a pass with nothing to send.
it('says at the console that the budget-nudge pass deferred rather than emitted', function (): void {
    $user = dnpUser();
    $this->enablesEncryptionForUser($user);
    dnpOverspentGroceries($user);

    dnpRunKeyless(static fn () => Artisan::call('budgets:emit-nudges'));

    expect(Artisan::output())
        ->toContain('Budget nudges: emitted for 0 users, deferred for 1 user')
        ->toContain('holds no app-lock key');
});

it('says at the console that the daily-trigger pass deferred rather than emitted', function (): void {
    $user = dnpUser();
    $this->enablesEncryptionForUser($user);

    dnpRunKeyless(static fn () => Artisan::call('notifications:daily-triggers'));

    expect(Artisan::output())->toContain('Daily triggers: emitted for 0 users, deferred for 1 user');
});

// The other half of the same sentence: a pass that DID emit must not borrow the
// deferral's wording, or the line stops distinguishing the two states it exists
// to distinguish.
it('names the users it emitted for when the process holds the key', function (): void {
    $user = dnpUser();
    dnpOverspentGroceries($user);

    app(SuppressionEvaluator::class)->suppressDelivery(static fn () => Artisan::call('budgets:emit-nudges'));

    expect(Artisan::output())->toContain('Budget nudges: emitted for 1 user.');
    expect(app(DeferredNotificationPasses::class)->outstandingFor((int) $user->id))->toBe([]);
});
