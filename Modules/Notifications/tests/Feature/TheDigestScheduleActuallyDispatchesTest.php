<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Jobs\EmitSavingsPromptsJob;
use Modules\Position\Internal\Jobs\EmitPositionDigestJob;
use Modules\Recurring\Internal\Jobs\EmitPaymentRemindersJob;

// ScheduleWiringTest proves the entry is registered on a minute the phone's
// runner can express. Nothing ran the work, so an argument the callee cannot
// accept stayed invisible: the scheduler reports the throw and nothing fires.

function tdsReader(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('dispatches all three daily triggers for each user once the local window opens', function (): void {
    CarbonImmutable::setTestNow('2026-08-29 09:15:00');
    Bus::fake();

    tdsReader('tds-reader');

    Artisan::call('notifications:daily-triggers');

    Bus::assertDispatched(EmitPaymentRemindersJob::class);
    Bus::assertDispatched(EmitPositionDigestJob::class);
    Bus::assertDispatched(EmitSavingsPromptsJob::class);
});

// The runner has no wall clock, so the command ticks all day and the gate is
// the only thing standing between a 09:15 digest and a 00:15 one.
it('dispatches nothing before the local window opens', function (): void {
    CarbonImmutable::setTestNow('2026-08-29 00:15:00');
    Bus::fake();

    tdsReader('tds-early-reader');

    Artisan::call('notifications:daily-triggers');

    Bus::assertNotDispatched(EmitPositionDigestJob::class);
});

it('dispatches once per local day, however many times the runner fires it', function (): void {
    CarbonImmutable::setTestNow('2026-08-29 09:15:00');
    Bus::fake();

    tdsReader('tds-repeat-reader');

    Artisan::call('notifications:daily-triggers');
    CarbonImmutable::setTestNow('2026-08-29 09:30:00');
    Artisan::call('notifications:daily-triggers');
    CarbonImmutable::setTestNow('2026-08-29 23:45:00');
    Artisan::call('notifications:daily-triggers');

    Bus::assertDispatchedTimes(EmitPositionDigestJob::class, 1);
});
