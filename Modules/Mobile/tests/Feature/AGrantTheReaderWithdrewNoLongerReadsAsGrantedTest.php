<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Notifications\NativeNotificationGrantState;
use Modules\Mobile\Internal\Notifications\NotificationGrantRecord;
use Modules\Mobile\Internal\Notifications\PlatformNotificationSwitch;
use Modules\Mobile\Tests\Support\AnsweringPlatformNotificationSwitch;
use Modules\Notifications\Public\Enums\SystemNotificationGrant;

uses(RefreshDatabase::class);

// The dialog is raised once and its answer written down. Nothing read it back,
// so a reader who granted the permission and then turned the app's
// notifications off in system settings left the app certain it could still
// reach them: the settings screen kept saying so, and the delivery record kept
// counting arrivals for notifications nobody saw.
//
// Measured on a Galaxy A51 (2026-09-09): POST_NOTIFICATIONS revoked from the
// app's own settings page, and mobile_notification_grant.granted still 1.

function withdrawnGrantReader(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function withdrawnGrantStateWith(?bool $platformAnswer): NativeNotificationGrantState
{
    app()->instance(PlatformNotificationSwitch::class, new AnsweringPlatformNotificationSwitch($platformAnswer));

    return app(NativeNotificationGrantState::class);
}

it('reads a grant the reader withdrew as refused', function (): void {
    $user = withdrawnGrantReader('grant-withdrawn');
    $this->actingAs($user);

    app(NotificationGrantRecord::class)->recordAnswer((int) $user->id, true);

    expect(withdrawnGrantStateWith(false)->current())->toBe(SystemNotificationGrant::Refused);
});

// The mirror image, and the reason the platform is asked rather than the
// record corrected: a reader who refuses and later turns notifications on is
// equally invisible, and the screen would keep telling them to go and enable
// something already enabled.
it('reads a refusal the reader reversed as granted', function (): void {
    $user = withdrawnGrantReader('grant-reversed');
    $this->actingAs($user);

    app(NotificationGrantRecord::class)->recordAnswer((int) $user->id, false);

    expect(withdrawnGrantStateWith(true)->current())->toBe(SystemNotificationGrant::Granted);
});

// An install whose shell predates the plugin patch cannot be asked. Reading
// that as a refusal would tell every one of them their device is dropping
// notifications it is showing.
it('keeps what was recorded when nothing could be asked', function (): void {
    $user = withdrawnGrantReader('grant-unreadable');
    $this->actingAs($user);

    app(NotificationGrantRecord::class)->recordAnswer((int) $user->id, true);

    expect(withdrawnGrantStateWith(null)->current())->toBe(SystemNotificationGrant::Granted);
});

// Before the dialog is answered the platform's "off" and "not yet asked" are
// the same reading, so neither state is overwritten by it.
it('does not turn an unasked or unanswered prompt into a refusal', function (string $stage, SystemNotificationGrant $expected): void {
    $user = withdrawnGrantReader('grant-'.$stage);
    $this->actingAs($user);

    if ($stage === 'awaiting') {
        app(NotificationGrantRecord::class)->markAsked((int) $user->id);
    }

    $switch = new AnsweringPlatformNotificationSwitch(false);
    app()->instance(PlatformNotificationSwitch::class, $switch);

    expect(app(NativeNotificationGrantState::class)->current())->toBe($expected)
        ->and($switch->reads)->toBe(0, 'the platform was asked a question its answer cannot settle');
})->with([
    'never asked' => ['never-asked', SystemNotificationGrant::NeverAsked],
    'awaiting' => ['awaiting', SystemNotificationGrant::Awaiting],
]);

it('has nothing to report for a guest', function (): void {
    expect(withdrawnGrantStateWith(false)->current())->toBe(SystemNotificationGrant::NotApplicable);
});
