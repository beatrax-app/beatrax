<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

// A typed code names a row that lives only in the database of the device that
// issued it, and the reader recovers it by asking the LAN for that device's
// pairing offer. Only a device running the sync listener answers, and no phone
// runs one — so a code minted on a phone is a code no other device can look up.
// The QR carries the identity inline and needs no lookup, which is why it is
// the only half of this step a phone can honestly offer.

function phoneCodeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function phoneCodeShowMyCode(User $user): string
{
    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    return Livewire::test(PairingFlowModal::class)->call('showMyCode')->html();
}

it('does not tell a phone reader to type its code on the other device', function (): void {
    $user = phoneCodeUser('phone-code-scan-only');
    $this->actingAs($user);

    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';

    try {
        $rendered = phoneCodeShowMyCode($user);
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }

    // Compared against the escaped copy, because Blade escapes the apostrophe
    // in the rendered line and the raw string would never match.
    expect($rendered)
        ->not->toContain(e(Lang::get('sync::pairing.enter_on_other')))
        ->and($rendered)->toContain(e(Lang::get('sync::pairing.scan_on_other')));
});

it('prints no word code on a phone, because nothing can look one up', function (): void {
    $user = phoneCodeUser('phone-code-no-words');
    $this->actingAs($user);

    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';

    try {
        $component = Livewire::test(PairingFlowModal::class);
        app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, app(Session::class));
        $component->call('showMyCode');
        $rendered = $component->html();
        $wordCode = (string) $component->get('wordCode');
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }

    expect($wordCode)->toBe('')
        ->and($rendered)->toContain('<svg');
});

it('keeps the word code and the typing instruction on a desktop, where a peer can look it up', function (): void {
    $user = phoneCodeUser('phone-code-desktop');
    $this->actingAs($user);

    $component = Livewire::test(PairingFlowModal::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, app(Session::class));
    $component->call('showMyCode');

    expect($component->get('wordCode'))->not->toBe('')
        ->and($component->html())->toContain(e(Lang::get('sync::pairing.enter_on_other')));
});

it('promises no word code on the screen where the reader picks a direction', function (): void {
    // The choose-direction card renders on both platforms, so a line naming
    // the word-code is false on one of them wherever it is read.
    expect(trans('sync::pairing.show_my_code_help', [], 'en'))
        ->not->toContain('word-code')
        ->not->toContain('word code');
});

it('offers the scan-only instruction in every locale', function (): void {
    $root = base_path('Modules/Sync/Resources/lang');
    $locales = array_values(array_filter(scandir($root) ?: [], static fn (string $e): bool => ! str_starts_with($e, '.')));

    expect($locales)->toHaveCount(26);

    $missing = [];
    foreach ($locales as $locale) {
        /** @var array<string, mixed> $pairing */
        $pairing = require $root.'/'.$locale.'/pairing.php';

        foreach (['scan_on_other', 'code_not_accepted', 'no_peer_answered', 'no_peer_search', 'rate_limited'] as $key) {
            $copy = $pairing[$key] ?? null;

            if (! is_string($copy) || trim($copy) === '') {
                $missing[] = $locale.'.'.$key;
            }
        }
    }

    expect($missing)->toBe([]);
});
