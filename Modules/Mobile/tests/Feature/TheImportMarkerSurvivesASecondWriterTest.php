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
// Where the gate no longer reads first, nothing fires and nothing is injected —
// which is the point: there is no window left to inject into.
function ctaMarkInjectAfterRead(int $userId): void
{
    $done = false;

    DB::listen(function ($query) use (&$done, $userId): void {
        if ($done || ! str_contains($query->sql, 'mobile_import_intent')) {
            return;
        }
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }

        $done = true;
        DB::table('mobile_import_intent')->insert([
            'user_id' => $userId,
            'created_at' => '2026-01-01T00:00:00Z',
        ]);
    });
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 08:30:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('marks the intent without raising when the other caller committed first', function (): void {
    $user = ctaMarkUser('race');

    ctaMarkInjectAfterRead((int) $user->id);

    /** @var MobileImportIntentGate $gate */
    $gate = app(MobileImportIntentGate::class);
    $gate->markImporting((int) $user->id);

    expect(DB::table('mobile_import_intent')->where('user_id', $user->id)->count())->toBe(1)
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
