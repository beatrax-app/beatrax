<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;

uses(RefreshDatabase::class);

// Http::fake() never reached the multicast socket underneath this arm, so the
// answer came from whatever was advertising on the developer's own wifi: a
// colleague's desktop turned a green suite red, and CI could not see it.
/**
 * @param  list<DiscoveredPeer>  $peers
 */
function manualArmDiscovers(array $peers): void
{
    app()->instance(PeerDiscovery::class, new class($peers) implements PeerDiscovery
    {
        /**
         * @param  list<DiscoveredPeer>  $peers
         */
        public function __construct(private readonly array $peers) {}

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

it('reads the import mode off the request that opened the screen', function (): void {
    $user = manualArmUser('armgate');
    test()->actingAs($user);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET'));
    Livewire::test(MobilePairingScan::class)->assertSet('importMode', false);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));
    Livewire::test(MobilePairingScan::class)->assertSet('importMode', true);
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

    expect(in_array('$importMode', $stack, true))->toBeFalse(
        'a phone whose camera is unusable must still have a route into the import',
    );
});

it('answers a typed code in import mode by looking for the other device on the network', function (): void {
    $user = manualArmUser('armlan');
    test()->actingAs($user);

    // Nothing advertising: the arm has to end in the "cannot reach the other
    // device" message rather than a spinner or a 500.
    manualArmDiscovers([]);
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    app()->instance(Request::class, Request::create('/mobile/pair', 'GET', ['mode' => 'import']));

    $wordCode = (new WordCodeEncoder)->encode(bin2hex(random_bytes(16)));

    Livewire::test(MobilePairingScan::class)
        ->assertSet('importMode', true)
        ->set('wordCode', $wordCode)
        ->call('submitCode', null)
        ->assertSet('step', 'enter_code')
        ->assertSet('pairingTokenId', '')
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.relay_unreachable'));
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
