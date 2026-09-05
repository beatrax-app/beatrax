<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Native\SafeStorageBackendProbe;
use Modules\Desktop\Tests\Support\StubElectronApi;

// safeStorage.isEncryptionAvailable() is true on a Linux box whose backend is
// Chromium's hardcoded-password fallback, so the desktop reported keychain
// protection on machines that had none and biometric enrolment wrote a wrap of
// the app-lock data key on the strength of it.

function backendProbeOn(string $platformFamily, StubElectronApi $api): SafeStorageBackendProbe
{
    return new SafeStorageBackendProbe($api, $platformFamily);
}

function backendReporting(string $backend): StubElectronApi
{
    return new StubElectronApi((string) json_encode(['result' => $backend]));
}

it('never asks the shell off Linux, where safeStorage has one backend', function (string $platformFamily): void {
    $api = backendReporting('basic_text');

    expect(backendProbeOn($platformFamily, $api)->protects())->toBeTrue()
        ->and($api->endpoints)->toBe([]);
})->with(['Darwin', 'Windows']);

it('accepts a Linux desktop whose safeStorage is behind a real keyring', function (string $backend): void {
    expect(backendProbeOn('Linux', backendReporting($backend))->protects())->toBeTrue();
})->with(['gnome_libsecret', 'kwallet', 'kwallet5', 'kwallet6']);

it('refuses a Linux desktop whose safeStorage protects nothing', function (string $backend): void {
    expect(backendProbeOn('Linux', backendReporting($backend))->protects())->toBeFalse();
})->with(['basic_text', 'unknown']);

// A bundle built before the prebuild hook existed, or one whose hook printed
// its failure and let the build carry on, has no such route. That silence is
// the case the old code read as protection.
it('fails closed on Linux when the route is absent', function (): void {
    $api = new StubElectronApi('<!DOCTYPE html>Cannot GET', 404);

    expect(backendProbeOn('Linux', $api)->protects())->toBeFalse()
        ->and($api->endpoints)->toBe(['system/'.SafeStorageBackendProbe::BACKEND_ROUTE]);
});

it('fails closed on Linux when the shell refuses the call', function (): void {
    expect(backendProbeOn('Linux', new StubElectronApi(refusesToConnect: true))->protects())->toBeFalse();
});

it('fails closed on Linux when the shell answers without a backend name', function (string $body): void {
    expect(backendProbeOn('Linux', new StubElectronApi($body))->protects())->toBeFalse();
})->with([
    '{"result":null}',
    '{"result":""}',
    '{"result":123}',
    '{}',
]);

// The answer cannot change while the shell is up, and the reader sits on the
// biometric-enrolment path and on every unlock render.
it('asks the shell once and reuses the answer', function (): void {
    $api = backendReporting('gnome_libsecret');
    $probe = backendProbeOn('Linux', $api);

    $probe->protects();
    $probe->protects();
    $probe->protects();

    expect($api->endpoints)->toHaveCount(1);
});

// Two spellings of one route: the const the reader asks for, and the literal
// the prebuild hook writes into the shell. Held together here because nothing
// else can — the build script runs with no autoloader.
it('asks for the route the prebuild hook injects', function (): void {
    require_once base_path('scripts/nativephp_inject_safe_storage_backend.php');

    [$patched, $status] = injectSafeStorageBackendRoute("const router = express.Router();\nexport default router;\n");

    expect($status)->toBe('patched')
        ->and($patched)->toContain("router.get('/".SafeStorageBackendProbe::BACKEND_ROUTE."'");
});
