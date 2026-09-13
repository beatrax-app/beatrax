<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;

// `relay:serve` chooses its TLS bind on this answer alone and then reads
// neither half, so material that is present but mismatched binds TLS and
// refuses every peer handshake — while the QR advertises https and a pin.

beforeEach(function (): void {
    $this->relayStorageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-relay-tls-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->relayStorageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

function relayForeignKeyPem(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    expect($key)->not->toBeFalse();
    $pem = '';
    openssl_pkey_export($key, $pem);

    return $pem;
}

it('calls generated material usable', function (): void {
    $tls = new RelayTlsMaterial;

    expect($tls->isUsable())->toBeFalse();

    $tls->ensure('192.0.2.10');

    expect($tls->isUsable())->toBeTrue();
});

it('refuses to call a present but mismatched pair usable', function (string $replacement): void {
    $tls = new RelayTlsMaterial;
    $tls->ensure('192.0.2.10');

    file_put_contents($tls->keyPath(), $replacement);

    expect($tls->isUsable())->toBeFalse();
})->with([
    'an empty file' => [''],
    'text that is not a key' => ['not a private key at all'],
    'a key from another generation' => [fn (): string => relayForeignKeyPem()],
]);

// Regenerating changes the pin, which is the point: the pin peers were given
// belongs to a key that opens nothing, so no peer could have used it anyway.
it('regenerates rather than serving TLS with a key that opens nothing', function (): void {
    $tls = new RelayTlsMaterial;
    $firstPin = $tls->ensure('192.0.2.10');

    file_put_contents($tls->keyPath(), relayForeignKeyPem());

    $secondPin = $tls->ensure('192.0.2.10');

    expect($tls->isUsable())->toBeTrue()
        ->and($secondPin)->not->toBe($firstPin)
        ->and($secondPin)->toStartWith('sha256//');
});

it('refuses a certificate with no key beside it', function (): void {
    $tls = new RelayTlsMaterial;
    $tls->ensure('192.0.2.10');

    unlink($tls->keyPath());

    expect($tls->isUsable())->toBeFalse();
});
