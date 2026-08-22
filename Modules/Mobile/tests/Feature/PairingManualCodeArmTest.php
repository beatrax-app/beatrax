<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;
use Modules\Sync\Public\Enums\PairingWizardStep;

uses(RefreshDatabase::class);

// Http::fake() never reached the multicast socket underneath this arm, so the
// answer came from whatever was advertising on the developer's own wifi: a
// colleague's desktop turned a green suite red, and CI could not see it.
/**
 * @param  list<DiscoveredPeer>  $peers
 */
function manualArmDiscovers(array $peers, LanDiscoveryReach $reach = LanDiscoveryReach::Available): void
{
    app()->instance(PeerDiscovery::class, new class($peers, $reach) implements PeerDiscovery
    {
        /**
         * @param  list<DiscoveredPeer>  $peers
         */
        public function __construct(private readonly array $peers, private readonly LanDiscoveryReach $reach) {}

        public function reach(): LanDiscoveryReach
        {
            return $this->reach;
        }

        /**
         * @return list<DiscoveredPeer>
         */
        public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
        {
            return $this->peers;
        }
    });
}

function manualArmUser(string $prefix): User
{
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function manualArmUnlockedIdentity(User $user, Session $session): void
{
    AppLockTestHarness::unlock($session, str_repeat('k', 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);
}

/** @return string the word code a desktop on this network is now answering for */
function manualArmDesktopOffering(): string
{
    manualArmDiscovers([new DiscoveredPeer('desktop-lan', '192.0.2.44', 51337, DiscoveryMode::Mdns)]);
    Http::fake(['*' => Http::response([
        'device_id' => 'desktop-initiator',
        'ed25519' => bin2hex(random_bytes(32)),
        'x25519' => bin2hex(random_bytes(32)),
        'name' => 'The desktop',
    ])]);

    return (new WordCodeEncoder)->encode(bin2hex(random_bytes(16)));
}

function manualArmBlade(): string
{
    return (string) file_get_contents(
        base_path('Modules/Mobile/Resources/views/livewire/mobile-pairing-scan.blade.php')
    );
}

// The submit button was wire:click="submitCode" with no argument, and
// submitCode()'s first parameter is the scanned QR payload, so Livewire tried to
// resolve it from the container and the request died with a
// BindingResolutionException: a 500, no message, and no way to submit a code.

it('submits a typed code without asking the container for the QR payload', function (): void {
    $blade = manualArmBlade();

    expect($blade)->toContain('wire:click="submitCode(null)"')
        ->and($blade)->not->toContain('wire:click="submitCode"');
});

// The query param is recorded, never trusted: mount() writes it into the durable
// marker and reads the marker back, so a relaunch that drops ?mode=import still
// mounts a screen that knows it is mid-import.
it('records the import intent durably from the request that opened the screen', function (): void {
    $user = manualArmUser('armgate');
    test()->actingAs($user);

    /** @var MobileImportIntentGate $importIntent */
    $importIntent = app(MobileImportIntentGate::class);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));
    Livewire::test(MobilePairingScan::class)->assertSet('importing', false);
    expect($importIntent->isImporting((int) $user->id))->toBeFalse();

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));
    Livewire::test(MobilePairingScan::class)->assertSet('importing', true);
    expect($importIntent->isImporting((int) $user->id))->toBeTrue();

    // The re-entry the marker exists for: same device, same flow, no query string.
    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));
    Livewire::test(MobilePairingScan::class)->assertSet('importing', true);
});

// The arm was once hidden from the import flow, because a typed code carries the
// token alone and the desktop never learned the joining device's identity. The
// importing device now asks the LAN for the public half the code cannot carry, so
// a phone whose camera is unusable has a route in again.

it('offers the typed-code arm while importing, not only outside import', function (): void {
    $blade = manualArmBlade();

    // Asserted on the blade because the arm lives on the `scan` step and a test
    // never reaches it: with no native scanner the component falls through to
    // `enter_code` at mount. Walked as balanced directives, since the offset of
    // the first @unless said nothing about whether the arm sat inside that one.
    $armAt = strpos($blade, 'wire:click="useWordCode"');
    expect($armAt)->not->toBeFalse('the typed-code control is gone entirely');

    $before = substr($blade, 0, (int) $armAt);
    preg_match_all('/@unless\s*\(([^)]*)\)|@endunless/', $before, $prior, PREG_SET_ORDER);

    $stack = [];
    foreach ($prior as $directive) {
        if (str_starts_with($directive[0], '@unless')) {
            $stack[] = trim($directive[1]);
        } else {
            array_pop($stack);
        }
    }

    expect(in_array('$importing', $stack, true))->toBeFalse(
        'a phone whose camera is unusable must still have a route into the import',
    );
});

it('answers a typed code in import mode by looking for the other device on the network', function (): void {
    $user = manualArmUser('armlan');
    test()->actingAs($user);

    // Nothing advertising: the arm has to end in a message rather than a
    // spinner or a 500.
    manualArmDiscovers([]);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    $wordCode = (new WordCodeEncoder)->encode(bin2hex(random_bytes(16)));

    Livewire::test(MobilePairingScan::class)
        ->assertSet('importing', true)
        ->set('wordCode', $wordCode)
        ->call('submitCode', null)
        ->assertSet('step', 'enter_code')
        ->assertSet('pairingTokenId', '')
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.no_peer_answered'));
});

// iphone-01. Both devices were on the same wifi, sync:serve was live and the
// desktop was advertising on seven interfaces, and the screen said none of that
// was true — under an unanswered "allow Beatrax to find devices on local
// networks?" prompt. Every clause of the message was a fact the phone had not
// established. The fixture supplies neither the permission nor a peer, because
// that is what the field supplies.

it('never blames the network for a silence it cannot explain', function (): void {
    $user = manualArmUser('armsilent');
    test()->actingAs($user);

    manualArmDiscovers([]);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    $flashed = Livewire::test(MobilePairingScan::class)
        ->set('wordCode', (new WordCodeEncoder)->encode(bin2hex(random_bytes(16))))
        ->call('submitCode', null)
        ->get('flashMessage');

    expect($flashed)->not->toBe(Lang::get('mobile::pairing.errors.relay_unreachable'));
    expect($flashed)->not->toContain('same network');
    expect($flashed)->not->toContain('sync is enabled on the desktop');
});

it('sends a device that cannot search to the camera rather than to its router', function (): void {
    $user = manualArmUser('armios');
    test()->actingAs($user);

    // Unsupported, not merely "no peers": the line is chosen by whether the
    // search could run at all, so an iPhone that later gains the entitlement
    // gets the ordinary sentence without anyone editing this branch.
    manualArmDiscovers([], LanDiscoveryReach::Unsupported);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    putenv('NATIVEPHP_PLATFORM=ios');

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    try {
        Livewire::test(MobilePairingScan::class)
            ->set('wordCode', (new WordCodeEncoder)->encode(bin2hex(random_bytes(16))))
            ->call('submitCode', null)
            ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.no_peer_answered_ios'))
            ->assertSet('flashMessage', fn (string $m): bool => str_contains($m, 'camera'));
    } finally {
        putenv('NATIVEPHP_PLATFORM');
    }
});

it('offers both no-answer lines in every locale', function (): void {
    $root = base_path('Modules/Mobile/Resources/lang');
    $locales = array_values(array_filter(scandir($root) ?: [], static fn (string $e): bool => ! str_starts_with($e, '.')));

    expect($locales)->toHaveCount(26);

    $missing = [];
    foreach ($locales as $locale) {
        /** @var array<string, mixed> $pairing */
        $pairing = require $root.'/'.$locale.'/pairing.php';
        $errors = $pairing['errors'] ?? [];

        foreach (['no_peer_answered', 'no_peer_answered_ios'] as $key) {
            $copy = is_array($errors) ? ($errors[$key] ?? null) : null;

            if (! is_string($copy) || $copy === '') {
                $missing[] = $locale.'.'.$key;
            }
        }
    }

    expect($missing)->toBe([]);
});

// A typed code names no device, so every desktop on the wifi gets asked and a
// housemate's laptop refuses a code it has never seen. Telling that reader
// their code is invalid is the same confident misdiagnosis, pointed the other
// way: the message has to be true whichever of the two happened.
it('does not call a good code invalid because some other desktop refused it', function (): void {
    $user = manualArmUser('armstranger');
    test()->actingAs($user);

    manualArmDiscovers([new DiscoveredPeer('a-housemates-mac', '192.0.2.9', 51337, DiscoveryMode::Mdns)]);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    $wordCode = (new WordCodeEncoder)->encode(bin2hex(random_bytes(16)));

    Livewire::test(MobilePairingScan::class)
        ->set('wordCode', $wordCode)
        ->call('submitCode', null)
        ->assertSet('step', 'enter_code')
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.code_not_accepted'))
        ->assertSet('flashMessage', fn (string $m): bool => $m !== Lang::get('mobile::pairing.errors.invalid_code'));
});

// desktop-03. The hub answers 429 distinctly and always did; the client folded
// it into CodeNotAccepted, so a rate-limited phone was told "This code is
// invalid or has expired. Ask the other device to generate a new one." Following
// that advice burns a fresh code into the same bucket and makes the limit worse
// — and android-07's evidence shows this phone re-emitting every 3 seconds.
it('tells a rate-limited phone to wait, not to burn a fresh code', function (): void {
    $user = manualArmUser('armlimited');
    test()->actingAs($user);

    manualArmDiscovers([new DiscoveredPeer('desktop-lan', '192.0.2.44', 51337, DiscoveryMode::Mdns)]);
    Http::fake(['*' => Http::response(['error' => 'rate_limited'], 429)]);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    $wordCode = (new WordCodeEncoder)->encode(bin2hex(random_bytes(16)));

    Livewire::test(MobilePairingScan::class)
        ->set('wordCode', $wordCode)
        ->call('submitCode', null)
        ->assertSet('step', 'enter_code')
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.rate_limited'))
        ->assertSet('flashMessage', fn (string $m): bool => $m !== Lang::get('mobile::pairing.errors.invalid_code'))
        ->assertSet('flashMessage', fn (string $m): bool => $m !== Lang::get('mobile::pairing.errors.code_not_accepted'));
});

it('offers the rate-limit copy in every locale', function (): void {
    $root = base_path('Modules/Mobile/Resources/lang');
    $locales = array_values(array_filter(scandir($root) ?: [], static fn (string $e): bool => ! str_starts_with($e, '.')));

    expect($locales)->toHaveCount(26);

    $missing = [];
    foreach ($locales as $locale) {
        /** @var array<string, mixed> $pairing */
        $pairing = require $root.'/'.$locale.'/pairing.php';
        $errors = $pairing['errors'] ?? [];
        $copy = is_array($errors) ? ($errors['rate_limited'] ?? null) : null;

        if (! is_string($copy) || $copy === '') {
            $missing[] = $locale;
        }
    }

    expect($missing)->toBe([]);
});

it('leaves no reference to the removed dead-end copy', function (): void {
    $en = require base_path('Modules/Mobile/Resources/lang/en/pairing.php');

    expect($en['errors'] ?? [])->not->toHaveKey('import_needs_qr');
});

// A phone holds its own database, so the row a typed code names exists only on
// the desktop that issued it: without the LAN lookup that seeds a local one,
// acceptWordCode() has nothing to accept against and every code reads as
// expired. The QR arm was ungated for the same reason one round earlier.
it('pairs from a typed code entered outside import mode', function (): void {
    $user = manualArmUser('armplain');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    manualArmUnlockedIdentity($user, $session);

    $wordCode = manualArmDesktopOffering();

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));

    Livewire::test(MobilePairingScan::class)
        ->assertSet('importing', false)
        ->set('wordCode', $wordCode)
        ->call('submitCode', null)
        ->assertSet('flashMessage', '')
        ->assertSet('step', PairingWizardStep::Confirm->value)
        ->assertSet('pairingTokenId', fn (string $id): bool => $id !== '');
});
