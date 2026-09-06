<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;

uses(RefreshDatabase::class);

// A typed code carries the token and nothing else, so the rest is fetched — and
// the only road to fetch it over was a browse. On a network that answers no
// browse, and on an iPhone where the browse never answers at all, that left the
// typed arm with nowhere to ask and a screen blaming the network for it.

function addressOfferUser(): User
{
    $user = User::query()->create([
        'username' => 'addroffer-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    return $user;
}

function addressOfferCode(): string
{
    return (new WordCodeEncoder)->encode(bin2hex(random_bytes(16)));
}

it('does not offer an address before anything has been asked', function (): void {
    addressOfferUser();

    Livewire::test(MobilePairingScan::class)
        ->assertSet('offerNeedsAnAddress', false)
        ->assertDontSee('initiator-address-input', escape: false);
});

it('offers an address once a submit reached nobody', function (): void {
    addressOfferUser();
    Http::fake(fn () => throw new ConnectionException('refused'));

    Livewire::test(MobilePairingScan::class)
        ->set('wordCode', addressOfferCode())
        ->call('submitCode', null)
        ->assertSet('offerNeedsAnAddress', true)
        ->assertSee('initiator-address-input', escape: false);
});

it('refuses an address the dial could not build a socket from, without asking the network', function (): void {
    addressOfferUser();

    $asked = 0;
    Http::fake(function () use (&$asked) {
        $asked++;

        return Http::response('', 404);
    });

    Livewire::test(MobilePairingScan::class)
        ->set('wordCode', addressOfferCode())
        ->set('initiatorAddress', 'ws://192.168.1.20:8100/sync')
        ->call('submitCode', null)
        ->assertSet('flashMessage', 'That is not an address this device can dial. Enter it as host and port, for example 192.168.1.20:8100.');

    // Refused before the dial, or the reader is told the network is at fault
    // for an address that was never dialable.
    expect($asked)->toBe(0, 'an unparsable address must not reach the network');
});
