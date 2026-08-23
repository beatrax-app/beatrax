<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SystemAlertWriter;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('stamps an alert with the app clock rather than the database default', function (): void {
    // The column defaults to CURRENT_TIMESTAMP, which SQLite always evaluates
    // in UTC. On a phone in CEST that put an alert raised at 01:38 into the
    // banner as 23:38 the previous day, two hours behind every other row.
    $user = User::query()->create([
        'username' => 'alert-clock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-05-20 01:38:47'));

    $alert = app(SystemAlertWriter::class)->raiseForUser(
        $user->id,
        'test.clock',
        'warning',
        'Raised while the clock was frozen.',
    );

    $stored = (string) DB::table('system_alerts')->where('id', $alert->id)->value('created_at');

    expect($stored)->toBe('2026-05-20 01:38:47');
});

it('stamps an alert written through the model directly', function (): void {
    // The probes and the scrub set write the model, not the writer, and they
    // were on the same default.
    Carbon::setTestNow(Carbon::parse('2026-05-20 01:38:47'));

    $alert = SystemAlert::query()->create([
        'user_id' => null,
        'kind' => 'test.clock.direct',
        'severity' => 'warning',
        'message' => 'Raised straight through the model.',
    ]);

    $stored = (string) DB::table('system_alerts')->where('id', $alert->id)->value('created_at');

    expect($stored)->toBe('2026-05-20 01:38:47');
});

it('writes no updated_at, because the table has no such column', function (): void {
    expect(SystemAlert::UPDATED_AT)->toBeNull();

    $alert = SystemAlert::query()->create([
        'user_id' => null,
        'kind' => 'test.clock.no-updated-at',
        'severity' => 'warning',
        'message' => 'No updated_at column exists.',
    ]);

    $alert->update(['acknowledged_at' => '2026-05-20 02:00:00']);

    expect((string) DB::table('system_alerts')->where('id', $alert->id)->value('acknowledged_at'))
        ->toBe('2026-05-20 02:00:00');
});
