<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

/*
 * Feature coverage for the signal POST /lock/resume hands back to lock.js.
 *
 * The answer must live in the response BODY. It used to be carried by the
 * redirect itself, which lock.js read as `response.redirected` — unreadable
 * under NativePHP's Android bridge, because the bridge follows the middleware's
 * redirect in-process and returns the lock page as an ordinary response. The
 * reload therefore never fired on the phone: the session was locked server-side
 * while the previous screen stayed rendered and accepted taps over it.
 *
 * So these pin the two halves lock.js distinguishes: an explicit unlocked body,
 * and an answer that is anything but.
 */

function lockResumeUser(bool $lockEnabled = true): User
{
    $user = User::query()->create([
        'username' => 'resume-user',
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
    ]);

    if ($lockEnabled) {
        DB::connection()->table('user_app_lock_configs')->insert([
            'user_id' => $user->id,
            'lock_enabled' => true,
            'idle_timeout_minutes' => 5,
            'failed_attempts' => 0,
            'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    return $user;
}

it('answers a within-grace resume with an explicit unlocked body', function (): void {
    $user = lockResumeUser();

    $response = $this->actingAs($user)->post('/lock/resume');

    $response->assertOk();
    $response->assertExactJson(['locked' => false]);
});

it('does not answer with the unlocked body once the session is locked', function (): void {
    $user = lockResumeUser();

    $response = $this->actingAs($user)
        ->withSession([LockStateManager::SESSION_KEY => true])
        ->post('/lock/resume');

    // Whatever the transport does with the redirect — followed in-process by
    // the mobile bridge, surfaced as a 302 in a browser — the one thing that
    // must never happen is the unlocked body coming back, because lock.js
    // stays put on exactly that and nothing else.
    expect($response->getContent())->not->toBe('{"locked":false}');
});
