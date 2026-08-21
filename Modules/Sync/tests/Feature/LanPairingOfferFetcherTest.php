<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Modules\Sync\Internal\Pairing\LanPairingOfferFetcher;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\MulticastMdnsQuery;

uses(RefreshDatabase::class);

// Discovery authenticates nothing, so every field here arrives from whoever
// answered a multicast question. The fetcher hands on a well-formed candidate
// identity or nothing at all, and never treats having answered as proof.

function lanOfferFetcher(): LanPairingOfferFetcher
{
    return new LanPairingOfferFetcher(
        app(HttpFactory::class),
        new MulticastMdnsQuery,
        new WordCodeEncoder,
    );
}

function lanOfferPeer(): DiscoveredPeer
{
    return new DiscoveredPeer('desktop-lan', '192.0.2.44', 51337, DiscoveryMode::Mdns);
}

it('accepts a well-formed offer as a candidate identity', function (): void {
    Http::fake(['*' => Http::response([
        'device_id' => 'desktop-lan',
        'ed25519' => str_repeat('a', 64),
        'x25519' => str_repeat('b', 64),
        'name' => 'Studio Mac',
    ])]);

    $offer = lanOfferFetcher()->offerFrom(lanOfferPeer(), str_repeat('c', 32));

    expect($offer)->not->toBeNull();
    expect($offer['deviceId'])->toBe('desktop-lan');
    expect($offer['ed25519PubHex'])->toBe(str_repeat('a', 64));
    expect($offer['x25519PubHex'])->toBe(str_repeat('b', 64));
    expect($offer['deviceName'])->toBe('Studio Mac');
    expect($offer['token'])->toBe(str_repeat('c', 32));
});

it('carries no relay bootstrap out of a LAN offer, whatever the peer sends', function (): void {
    // A peer answering with relay fields is either an older build or an
    // attacker; either way this path must never learn a relay from the wire.
    Http::fake(['*' => Http::response([
        'device_id' => 'desktop-lan',
        'ed25519' => str_repeat('a', 64),
        'x25519' => str_repeat('b', 64),
        'name' => 'Studio Mac',
        'relay' => 'https://attacker.invalid',
        'rtok' => 'stolen-bearer',
        'rpin' => 'stolen-pin',
    ])]);

    $offer = lanOfferFetcher()->offerFrom(lanOfferPeer(), str_repeat('c', 32));

    expect($offer)->not->toBeNull();
    expect($offer['relayEndpoint'])->toBeNull();
    expect($offer['relayAuthToken'])->toBeNull();
    expect($offer['relayPin'])->toBeNull();
});

it('asks over plaintext http on the peer own sync port', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    lanOfferFetcher()->offerFrom(lanOfferPeer(), str_repeat('c', 32));

    Http::assertSent(static fn ($request): bool => str_starts_with(
        $request->url(),
        'http://192.0.2.44:51337/pair/offer?token=',
    ));
});

it('treats a peer that does not hold the token as not the one', function (): void {
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    expect(lanOfferFetcher()->offerFrom(lanOfferPeer(), str_repeat('c', 32)))->toBeNull();
});

it('refuses an offer whose key material is malformed', function (): void {
    Http::fake(['*' => Http::response([
        'device_id' => 'desktop-lan',
        'ed25519' => 'not-hex',
        'x25519' => str_repeat('b', 64),
    ])]);

    expect(lanOfferFetcher()->offerFrom(lanOfferPeer(), str_repeat('c', 32)))->toBeNull();
});

it('refuses an offer missing the device id', function (): void {
    Http::fake(['*' => Http::response([
        'ed25519' => str_repeat('a', 64),
        'x25519' => str_repeat('b', 64),
    ])]);

    expect(lanOfferFetcher()->offerFrom(lanOfferPeer(), str_repeat('c', 32)))->toBeNull();
});

it('refuses an answer that is not JSON at all', function (): void {
    Http::fake(['*' => Http::response('<html>hello</html>')]);

    expect(lanOfferFetcher()->offerFrom(lanOfferPeer(), str_repeat('c', 32)))->toBeNull();
});

it('drops an oversized device id rather than passing it on', function (): void {
    Http::fake(['*' => Http::response([
        'device_id' => str_repeat('d', 4096),
        'ed25519' => str_repeat('a', 64),
        'x25519' => str_repeat('b', 64),
    ])]);

    expect(lanOfferFetcher()->offerFrom(lanOfferPeer(), str_repeat('c', 32)))->toBeNull();
});

it('yields nothing for a word-code that is not a pairing code', function (): void {
    Http::fake();

    expect(lanOfferFetcher()->fetchForWordCode('NOPE'))->toBeNull();

    // A code that cannot decode must not reach the network at all.
    Http::assertNothingSent();
});
