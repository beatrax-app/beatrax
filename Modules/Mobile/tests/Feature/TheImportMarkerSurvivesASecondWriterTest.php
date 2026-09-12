<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;

// Two independent callers mark the import intent — the provisioning step and
// the pairing screen's mount — and the gate's comment promised a second call
// was a no-op. It was not: the check and the insert were two statements, and
// unique(user_id) refused the loser. The marker is what keeps a phone in the
// ceremony it abandoned, so a raise here strands the device rather than the row.

function ctaMarkUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cta-mark-'.$suffix.'-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

// The other caller, committed at the instant the check has just read "absent".
//
// Armed for the call under test and disarmed again, because the gate no longer
// reads before it writes: a listener left live would first fire on the
// assertions below, after the marker is already there, and insert a second one
// nothing ever raced for. That is a defect in the harness rather than a
// finding, and it reads as the gate raising when the gate is fine.
function ctaMarkUnderRace(int $userId, Closure $act): void
{
    $armed = true;
    $injected = false;

    DB::listen(function ($query) use (&$armed, &$injected, $userId): void {
        if (! $armed || $injected || ! str_contains($query->sql, 'mobile_import_intent')) {
            return;
        }
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }

        $injected = true;
        DB::table('mobile_import_intent')->insert([
            'user_id' => $userId,
            'created_at' => '2026-01-01T00:00:00Z',
        ]);
    });

    try {
        $act();
    } finally {
        $armed = false;
    }
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 08:30:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The direct statement of the fix, and it needs no second writer to make the
// point: there is no longer a read in front of the write whose answer another
// caller could invalidate. The old shape issues select then insert.
it('marks the intent without reading the table first', function (): void {
    $user = ctaMarkUser('statements');

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'mobile_import_intent')) {
            $statements[] = explode(' ', strtolower(ltrim($query->sql)))[0];
        }
    });

    app(MobileImportIntentGate::class)->markImporting((int) $user->id);

    expect($statements)->toBe(['insert']);
});

it('marks the intent without raising when the other caller committed first', function (): void {
    $user = ctaMarkUser('race');

    /** @var MobileImportIntentGate $gate */
    $gate = app(MobileImportIntentGate::class);

    ctaMarkUnderRace((int) $user->id, function () use ($gate, $user): void {
        $gate->markImporting((int) $user->id);
    });

    $rows = DB::table('mobile_import_intent')->where('user_id', $user->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->created_at)->toBe('2026-05-20T08:30:00Z')
        ->and($gate->isImporting((int) $user->id))->toBeTrue();
});

it('keeps the first stamp when the marker is set twice, because that is when the import began', function (): void {
    $user = ctaMarkUser('stamp');

    /** @var MobileImportIntentGate $gate */
    $gate = app(MobileImportIntentGate::class);
    $gate->markImporting((int) $user->id);

    CarbonImmutable::setTestNow('2026-05-20 09:45:00');
    $gate->markImporting((int) $user->id);

    $rows = DB::table('mobile_import_intent')->where('user_id', $user->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->created_at)->toBe('2026-05-20T08:30:00Z');
});

it('clears a marker that was set twice, leaving nothing behind', function (): void {
    $user = ctaMarkUser('clear');

    /** @var MobileImportIntentGate $gate */
    $gate = app(MobileImportIntentGate::class);
    $gate->markImporting((int) $user->id);
    $gate->markImporting((int) $user->id);
    $gate->clearImporting((int) $user->id);

    expect(DB::table('mobile_import_intent')->where('user_id', $user->id)->count())->toBe(0)
        ->and($gate->isImporting((int) $user->id))->toBeFalse();
});
