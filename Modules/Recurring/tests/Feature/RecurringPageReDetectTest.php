<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\Http\Livewire\RecurringPage;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;

/*
 * "Re-detect now" button on /recurring. Dispatches the same
 * DetectRecurringSeriesJob the daily sweep dispatches, but via
 * dispatchSync (14.1-04, CRYPT-01): the KEK needed to decrypt
 * counterparty_iban for the detectors is only reachable through the
 * live, unlocked Session on this request, so the job now runs
 * in-process rather than being queued. dispatchSync bypasses the
 * queue-push boundary entirely, so the job's per-user
 * ShouldBeUniqueUntilProcessing lock (queue-only) no longer collapses
 * spam-clicks — each click now runs a full, idempotent, redundant
 * pass instead.
 *
 * The arch invariant `noSynchronousDetectionInRequestLifecycle`
 * forbids the SFC from importing a `SeriesDetector` directly — only
 * the Job class is allowed at the HTTP layer. This is unaffected by
 * the dispatchSync change: the SFC still only references
 * `DetectRecurringSeriesJob`, never `SeriesDetector`.
 */

function rprdUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->user = rprdUser('rprd');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('dispatches DetectRecurringSeriesJob synchronously with the caller`s userId (re-detect-dispatches-job)', function (): void {
    Bus::fake();

    Livewire::actingAs($this->user)
        ->test(RecurringPage::class)
        ->call('reDetect');

    Bus::assertDispatchedSync(
        DetectRecurringSeriesJob::class,
        fn (DetectRecurringSeriesJob $job): bool => $job->userId === $this->user->id,
    );
})->group('re-detect-dispatches-job');

it('records both sync dispatches for spam-clicks; the queue-only unique lock no longer collapses them (re-detect-spam-click-collapses)', function (): void {
    Bus::fake();

    $component = Livewire::actingAs($this->user)->test(RecurringPage::class);
    $component->call('reDetect');
    $component->call('reDetect');

    Bus::assertDispatchedSync(DetectRecurringSeriesJob::class, 2);
})->group('re-detect-spam-click-collapses');

it('fires a `Detecting recurring series…` toast on dispatch (re-detect-toast-fires)', function (): void {
    Bus::fake();

    $component = Livewire::actingAs($this->user)
        ->test(RecurringPage::class)
        ->call('reDetect');

    $component->assertDispatched(
        'toast',
        fn (string $event, array $params): bool => ($params['message'] ?? '') === 'Detecting recurring series…',
    );
})->group('re-detect-toast-fires');

it('keeps the noSynchronousDetectionInRequestLifecycle arch test green when RecurringPage gains reDetect (re-detect-arch-test-still-green)', function (): void {
    $contents = (string) file_get_contents(
        base_path('Modules/Recurring/Internal/Http/Livewire/RecurringPage.php')
    );

    expect($contents)->toContain('DetectRecurringSeriesJob');
    // The SFC must reference the job class — never a SeriesDetector.
    expect($contents)->not->toContain('ExpenseSeriesDetector');
    expect($contents)->not->toContain('IncomeSeriesDetector');
    expect($contents)->not->toContain('SeriesDetector');
})->group('re-detect-arch-test-still-green');
