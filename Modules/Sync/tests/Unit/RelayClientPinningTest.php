<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Relay\RelayClient;

// The relay speaks TLS with verify=>false paired with CURLOPT_PINNEDPUBLICKEY and
// fails closed when the runtime cannot pin, because that option is a silent no-op
// on some curl backends: admitting one would ship verify=>false behind an inert
// pin, which is unauthenticated TLS on the LAN.

it('only treats pin-honoring TLS backends as pinnable (fail-closed allow-list)', function (string $sslVersion, bool $honors): void {
    expect(RelayClient::backendHonorsPinning($sslVersion))->toBe($honors);
})->with([
    'OpenSSL' => ['OpenSSL/3.0.13', true],
    'LibreSSL' => ['LibreSSL/3.3.6', true],
    'BoringSSL' => ['BoringSSL', true],
    'GnuTLS' => ['GnuTLS/3.7.9', true],
    // Schannel and Secure Transport silently ignore CURLOPT_PINNEDPUBLICKEY, so
    // pinning there is inert; they must be refused, not trusted.
    'Schannel' => ['Schannel', false],
    'Secure Transport' => ['SecureTransport', false],
    'mbedTLS' => ['mbedTLS/2.28.0', false],
    'wolfSSL' => ['wolfSSL/5.6.0', false],
    'unknown' => ['unknown', false],
    'empty' => ['', false],
]);
