<?php

declare(strict_types=1);

use Amp\Http\HttpStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Modules\Sync\Internal\Pairing\LanPairingOfferFetcher;
use Modules\Sync\Internal\Pairing\LanPeerBrowser;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\PairingOfferLookup;

uses(RefreshDatabase::class);

// Discovery authenticates nothing, so every field here arrives from whoever
// answered a multicast question. The fetcher hands on a well-formed candidate
// identity or WHICH ending it hit, and never treats having answered as proof.
//
// Every case drives fetchForWordCode(), the surface the screen actually calls.
// Discovery is stubbed rather than left to real multicast: the previous shape
// opened a real socket, so a colleague's desktop advertising on the same wifi
// decided the result and the suite failed on their machine and not on CI.

/**
 * @param  list<DiscoveredPeer>  $peers
 */
function lanOfferFetcher(array $peers = []): LanPairingOfferFetcher
{
    $discovery = new class($peers) implements PeerDiscovery
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
    };

    return new LanPairingOfferFetcher(new LanPeerBrowser(app(HttpFactory::class), $discovery), new WordCodeEncoder);
}

function lanOfferPeer(): DiscoveredPeer
{
    return new DiscoveredPeer('desktop-lan', '192.0.2.44', 51337, DiscoveryMode::Mdns);
}

/**
 * @return array{0: string, 1: string} the word code to type, and the token it decodes to
 */
function lanOfferCode(): array
{
    $tokenHex = bin2hex(random_bytes(16));

    return [(new WordCodeEncoder)->encode($tokenHex), $tokenHex];
}

it('accepts a well-formed offer as a candidate identity', function (): void {
    Http::fake(['*' => Http::response([
        'device_id' => 'desktop-lan',
        'ed25519' => str_repeat('a', 64),
        'x25519' => str_repeat('b', 64),
        'name' => 'Studio Mac',
    ])]);

    [$wordCode, $tokenHex] = lanOfferCode();

    $offer = lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode);

    expect($offer)->toBeArray();
    expect($offer['deviceId'])->toBe('desktop-lan');
    expect($offer['ed25519PubHex'])->toBe(str_repeat('a', 64));
    expect($offer['x25519PubHex'])->toBe(str_repeat('b', 64));
    expect($offer['deviceName'])->toBe('Studio Mac');
    expect($offer['token'])->toBe($tokenHex);
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

    [$wordCode] = lanOfferCode();

    $offer = lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode);

    expect($offer)->toBeArray();
    expect($offer['relayEndpoint'])->toBeNull();
    expect($offer['relayAuthToken'])->toBeNull();
    expect($offer['relayPin'])->toBeNull();
});

it('asks over plaintext http on the peer own sync port', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    [$wordCode] = lanOfferCode();

    lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode);

    Http::assertSent(static fn ($request): bool => str_starts_with(
        $request->url(),
        'http://192.0.2.44:51337/pair/offer?token=',
    ));
});

it('never sends the token itself, only its hash', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    [$wordCode, $tokenHex] = lanOfferCode();

    lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode);

    Http::assertSent(static fn ($request): bool => str_contains($request->url(), hash('sha256', $tokenHex))
        && ! str_contains($request->url(), $tokenHex));
});

it('refuses an offer whose key material is malformed', function (): void {
    Http::fake(['*' => Http::response([
        'device_id' => 'desktop-lan',
        'ed25519' => 'not-hex',
        'x25519' => str_repeat('b', 64),
    ])]);

    [$wordCode] = lanOfferCode();

    expect(lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode))
        ->toBe(PairingOfferLookup::CodeNotAccepted);
});

it('refuses an offer missing the device id', function (): void {
    Http::fake(['*' => Http::response([
        'ed25519' => str_repeat('a', 64),
        'x25519' => str_repeat('b', 64),
    ])]);

    [$wordCode] = lanOfferCode();

    expect(lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode))
        ->toBe(PairingOfferLookup::CodeNotAccepted);
});

it('refuses an answer that is not JSON at all', function (): void {
    Http::fake(['*' => Http::response('<html>hello</html>')]);

    [$wordCode] = lanOfferCode();

    expect(lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode))
        ->toBe(PairingOfferLookup::CodeNotAccepted);
});

it('drops an oversized device id rather than passing it on', function (): void {
    Http::fake(['*' => Http::response([
        'device_id' => str_repeat('d', 4096),
        'ed25519' => str_repeat('a', 64),
        'x25519' => str_repeat('b', 64),
    ])]);

    [$wordCode] = lanOfferCode();

    expect(lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode))
        ->toBe(PairingOfferLookup::CodeNotAccepted);
});

// The lookup used to answer every one of these endings with a bare null, so the
// screen that asks could only ever say one thing — and what it said was "check
// that both devices are on the same network", which is true for exactly one of
// them. A reader whose code had expired was sent to debug a healthy network.
it('blames the code, not the network, for a word-code that is not a pairing code', function (): void {
    Http::fake();

    expect(lanOfferFetcher([lanOfferPeer()])->fetchForWordCode('NOPE'))
        ->toBe(PairingOfferLookup::CodeNotAccepted);

    // A code that cannot decode must not reach the network at all.
    Http::assertNothingSent();
});

it('blames the code when a peer answered and refused the token', function (): void {
    // 404 is the peer refusing this token — it deliberately answers an unknown,
    // an expired and another user's token identically. It IS reachable, so the
    // network is not the story.
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    [$wordCode] = lanOfferCode();

    expect(lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode))
        ->toBe(PairingOfferLookup::CodeNotAccepted);
});

it('blames the network when nothing on it answered at all', function (): void {
    Http::fake(fn () => throw new ConnectionException('refused'));

    [$wordCode] = lanOfferCode();

    expect(lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode))
        ->toBe(PairingOfferLookup::NoPeerReached);
});

it('blames the network when discovery found nothing to ask', function (): void {
    Http::fake();

    [$wordCode] = lanOfferCode();

    expect(lanOfferFetcher()->fetchForWordCode($wordCode))
        ->toBe(PairingOfferLookup::NoPeerReached);

    Http::assertNothingSent();
});

// A peer that answered a browse but cannot be addressed was never asked, so it
// must not count as a peer that refused the code.
it('skips a peer that announced itself without an address', function (): void {
    Http::fake();

    [$wordCode] = lanOfferCode();

    $unaddressable = new DiscoveredPeer('desktop-lan', '', 0, DiscoveryMode::Mdns);

    expect(lanOfferFetcher([$unaddressable])->fetchForWordCode($wordCode))
        ->toBe(PairingOfferLookup::NoPeerReached);

    Http::assertNothingSent();
});

// desktop-03: a rate-limited phone was told "This code is invalid or has
// expired. Ask the other device to generate a new one." — advice that cannot
// work, and that sends the reader to burn a fresh code into the same bucket.
// The hub answers 429 distinctly and always did; the client collapsed it.

it('blames the rate limiter, not the code, when a peer answered 429', function (): void {
    Http::fake(['*' => Http::response(['error' => 'rate_limited'], HttpStatus::TOO_MANY_REQUESTS)]);

    [$wordCode] = lanOfferCode();

    expect(lanOfferFetcher([lanOfferPeer()])->fetchForWordCode($wordCode))
        ->toBe(PairingOfferLookup::RateLimited);
});

// Both peers answered, so neither is a network problem. Telling the reader to
// wait is true whichever peer holds their code; telling them to regenerate is
// false for the limited one and makes the limit worse.
it('lets a 429 from any peer outrank a 404 from another', function (): void {
    $responses = [
        Http::response(['error' => 'not_found'], 404),
        Http::response(['error' => 'rate_limited'], HttpStatus::TOO_MANY_REQUESTS),
    ];
    Http::fake(['*' => Http::sequence($responses)]);

    [$wordCode] = lanOfferCode();

    $peers = [
        new DiscoveredPeer('desktop-a', '192.0.2.44', 51337, DiscoveryMode::Mdns),
        new DiscoveredPeer('desktop-b', '192.0.2.45', 51337, DiscoveryMode::Mdns),
    ];

    expect(lanOfferFetcher($peers)->fetchForWordCode($wordCode))
        ->toBe(PairingOfferLookup::RateLimited);
});

// A live offer is still a live offer: being limited by a different peer must
// not throw away the identity the right one handed over.
it('still returns the identity when one peer 429s and another answers with the offer', function (): void {
    Http::fake(['*' => Http::sequence([
        Http::response(['error' => 'rate_limited'], HttpStatus::TOO_MANY_REQUESTS),
        Http::response([
            'device_id' => 'desktop-lan',
            'ed25519' => str_repeat('a', 64),
            'x25519' => str_repeat('b', 64),
        ]),
    ])]);

    [$wordCode, $tokenHex] = lanOfferCode();

    $peers = [
        new DiscoveredPeer('desktop-a', '192.0.2.44', 51337, DiscoveryMode::Mdns),
        new DiscoveredPeer('desktop-b', '192.0.2.45', 51337, DiscoveryMode::Mdns),
    ];

    $result = lanOfferFetcher($peers)->fetchForWordCode($wordCode);

    expect($result)->toBeArray();
    expect($result['token'])->toBe($tokenHex);
});
