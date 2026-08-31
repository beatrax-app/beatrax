<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectEmailStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\WelcomeStep;

// The wizard's step registry carries connect-email on every platform, and both
// its heading and the welcome step's third promise said Beatrax would capture
// receipts automatically. The inbox pipeline is five Schedule::call() closures,
// every one of them in MobileBackgroundSchedule::desktopOnly().

function tesnUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
});

it('keeps the automatic capture promise on the desktop that keeps it', function (): void {
    $this->actingAs(tesnUser('welcome-desktop'));

    Livewire::test(WelcomeStep::class)
        ->assertSee('Connect Gmail or Outlook to capture purchase confirmations automatically.')
        ->assertDontSee('captured by the desktop app');
});

it('does not open the wizard on a phone by promising automatic capture', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');
    $this->actingAs(tesnUser('welcome-phone'));

    Livewire::test(WelcomeStep::class)
        ->assertSee('captured by the desktop app')
        ->assertDontSee('Connect Gmail or Outlook to capture purchase confirmations automatically.');
});

it('keeps the watch-for-purchase-emails step copy on a desktop', function (): void {
    $this->actingAs(tesnUser('connect-desktop'));

    Livewire::test(ConnectEmailStep::class)
        ->assertSee('Let Beatrax watch for purchase emails')
        ->assertSee('Connect Gmail or Outlook so order confirmations and subscription receipts auto-attach to your transactions.')
        ->assertDontSee('Nothing on this phone reads a mailbox');
});

it('does not tell a phone reader that Beatrax will watch their mail from here', function (): void {
    putenv('NATIVEPHP_PLATFORM=android');
    $this->actingAs(tesnUser('connect-phone'));

    Livewire::test(ConnectEmailStep::class)
        ->assertSee('Purchase emails are watched on the desktop')
        ->assertSee('Nothing on this phone reads a mailbox, so skip this step here and connect on the desktop.')
        ->assertDontSee('Let Beatrax watch for purchase emails');
});
