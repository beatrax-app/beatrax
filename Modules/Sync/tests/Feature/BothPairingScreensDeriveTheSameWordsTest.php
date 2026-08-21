<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

function bothScreensUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('both-screens'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Drives the desktop modal to the confirm step on a row a responder has just
// bound. The poll is what derives the words, so the tap that follows is bound
// to a comparison the human could actually have made.
function bothScreensDesktopAtConfirm(User $user, string $responderEd = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'): object
{
    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    $component = Livewire::test(PairingFlowModal::class)->call('showMyCode');

    $accepted = app(PairingTokenService::class)->accept(
        app(WordCodeEncoder::class)->decode($component->get('wordCode')),
        (int) $user->id,
        'phone-responder',
        $responderEd,
        str_repeat('d', 64),
    );

    expect($accepted)->not->toBeFalse();

    return $component;
}

it('shows the identical six words on the desktop screen and through the reader the phone screen uses', function (): void {
    $user = bothScreensUser('both-screens-agree');
    test()->actingAs($user);

    $component = bothScreensDesktopAtConfirm($user);
    $component->call('checkPairingState')->assertSet('step', 'confirm');

    $tokenId = (int) $component->get('pairingTokenId');

    // The two screens must reach the same six words from the same row, or the
    // comparison the human makes between them means nothing.
    $desktopWords = $component->get('safetyWords');
    $phoneWords = app(PairingGateway::class)->safetyWordsFor($tokenId, (int) $user->id);

    expect($desktopWords)->toHaveCount(6);
    expect($desktopWords)->toBe($phoneWords);
});

it('shows nothing on either screen when a bound key stops being decodable', function (): void {
    $user = bothScreensUser('both-screens-bad-key');
    test()->actingAs($user);

    $component = bothScreensDesktopAtConfirm($user);
    $tokenId = (int) $component->get('pairingTokenId');

    // Written straight onto the row, because accept() refuses this at the
    // trust boundary — the only way a malformed key reaches a derivation is
    // a row that predates that guard.
    app(DatabaseManager::class)->connection()->table('pairing_tokens')
        ->where('id', $tokenId)
        ->update(['responder_ed25519_pub_hex' => str_repeat('z', 64)]);

    $component->call('checkPairingState');

    expect($component->get('safetyWords'))->toBe([]);
    expect(app(PairingGateway::class)->safetyWordsFor($tokenId, (int) $user->id))->toBe([]);
});

it('shows nothing on either screen while only the initiator has bound a key', function (): void {
    $user = bothScreensUser('both-screens-half-bound');
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    $component = Livewire::test(PairingFlowModal::class)->call('showMyCode');
    $tokenId = (int) $component->get('pairingTokenId');

    $component->call('checkPairingState');

    expect($component->get('safetyWords'))->toBe([]);
    expect(app(PairingGateway::class)->safetyWordsFor($tokenId, (int) $user->id))->toBe([]);
});
