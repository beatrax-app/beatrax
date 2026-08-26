<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Public\Enums\PairingWizardStep;

uses(RefreshDatabase::class);

function typedCodeUser(): User
{
    $user = User::query()->create([
        'username' => 'typedcode-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    return $user;
}

// Observed on hardware: a code typed on the phone, submitted, and met with "your
// device identity is locked" was gone after the PIN. mount() ends in enterACode(),
// which clears wordCode and sends a camera-capable device back to the scanner —
// so the reader retyped 26 characters against what was left of a ten-minute TTL.
it('brings a code typed before the lock back to the code field', function (): void {
    typedCodeUser();

    app(Session::class)->put(MobilePairingScan::TYPED_CODE_SESSION, 'ICHD-6QT5-3JTX-2AAD-J7KF-ACRN-CA');

    Livewire::test(MobilePairingScan::class)
        ->assertSet('wordCode', 'ICHD-6QT5-3JTX-2AAD-J7KF-ACRN-CA')
        ->assertSet('step', PairingWizardStep::EnterCode->value);
});

// The stash is consumed, not left lying about: a later arrival that typed nothing
// must get the ordinary scanner default, not the last reader's code.
it('does not resurrect the code on the next arrival', function (): void {
    typedCodeUser();

    app(Session::class)->put(MobilePairingScan::TYPED_CODE_SESSION, 'ICHD-6QT5-3JTX-2AAD-J7KF-ACRN-CA');

    Livewire::test(MobilePairingScan::class)->assertSet('wordCode', 'ICHD-6QT5-3JTX-2AAD-J7KF-ACRN-CA');

    expect(app(Session::class)->get(MobilePairingScan::TYPED_CODE_SESSION))->toBeNull();

    Livewire::test(MobilePairingScan::class)->assertSet('wordCode', '');
});

// sendToUnlock() is the only writer, and it must not stash an empty field: a
// scanned QR reaches the same branch with nothing typed.
it('stashes the typed code on the way to the pin pad and nothing when there is none', function (): void {
    $component = (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Http/Livewire/MobilePairingScan.php')
    );

    $sendToUnlock = substr($component, (int) strpos($component, 'private function sendToUnlock('));
    $sendToUnlock = substr($sendToUnlock, 0, (int) strpos($sendToUnlock, "\n    }"));

    expect(str_contains($sendToUnlock, 'self::TYPED_CODE_SESSION'))
        ->toBeTrue('sendToUnlock() sends the reader to the PIN pad and drops the code they typed')
        ->and(str_contains($sendToUnlock, "\$this->wordCode !== ''"))
        ->toBeTrue('sendToUnlock() stashes an empty code for a scanned QR');
});
