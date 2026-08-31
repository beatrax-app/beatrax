<?php

declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\Desktop\Internal\Native\BoundedNativeApiClient;
use Native\Desktop\Client\Client as NativeApiClient;

// The desktop backend serves one request at a time, and NativePHP allows its
// Electron API an hour per call. Repo code reaches that client from an ordinary
// web request — spawning the sync listener when sync is enabled — so an Electron
// process that accepts the connection and then stops answering holds the whole
// application for the hour.

function boundedClientTimeout(NativeApiClient $client): mixed
{
    $pending = (new ReflectionProperty(NativeApiClient::class, 'client'))->getValue($client);

    expect($pending)->toBeInstanceOf(PendingRequest::class);

    return $pending->getOptions()['timeout'] ?? null;
}

beforeEach(function (): void {
    config()->set('nativephp-internal.api_url', 'http://127.0.0.1:4001/api/');
    config()->set('nativephp-internal.secret', 'fixture-secret');

    Http::fake(['*' => Http::response(['result' => true], 200)]);
});

it('resolves the bounded client wherever NativePHP asks for its API client', function (): void {
    // Bound at the contract NativePHP autowires, so the ChildProcess, Window and
    // System implementations all receive it — not just the one caller measured.
    expect(app(NativeApiClient::class))->toBeInstanceOf(BoundedNativeApiClient::class);
});

it('bounds a child-process spawn to seconds rather than the vendor hour', function (): void {
    $client = app(NativeApiClient::class);
    $client->post('child-process/start', ['alias' => 'sync-listener']);

    expect(boundedClientTimeout($client))
        ->toBe(BoundedNativeApiClient::TIMEOUT_SECONDS)
        ->and(BoundedNativeApiClient::TIMEOUT_SECONDS)->toBeLessThan(60 * 60);
});

it('leaves the Touch ID prompt room to wait on a person', function (): void {
    // A machine-scale bound here would cancel the sheet out from under the
    // reader — which is a lock screen they cannot clear, not a saved second.
    $client = app(NativeApiClient::class);
    $client->post('system/prompt-touch-id', ['reason' => 'Unlock Beatrax']);

    expect(boundedClientTimeout($client))->toBe(BoundedNativeApiClient::PERSON_TIMEOUT_SECONDS);
});

it('does not leave the prompt widening in place for the next call', function (): void {
    // One PendingRequest is shared across every call, so a widening that is not
    // reset makes the call after an unlock inherit the person-scale bound.
    $client = app(NativeApiClient::class);

    $client->post('system/prompt-touch-id', ['reason' => 'Unlock Beatrax']);
    $client->post('child-process/start', ['alias' => 'sync-listener']);

    expect(boundedClientTimeout($client))->toBe(BoundedNativeApiClient::TIMEOUT_SECONDS);
});
