<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

// The answer has to live in the body: the Android bridge follows a redirect
// in-process, so the reload never fired and the previous screen stayed
// rendered, accepting taps over a server-side locked session.

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

    // Whatever the transport does with the redirect, the unlocked body must
    // never come back: lock.js stays put on exactly that and nothing else.
    expect($response->getContent())->not->toBe('{"locked":false}');
});
