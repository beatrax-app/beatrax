<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\NullKeyCustodian;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Auth\Public\Enums\KeyCustody;
use Modules\Core\Public\Support\PatternScan;
use Modules\Desktop\Internal\Native\DesktopKeyCustodian;
use Modules\Mobile\Internal\Identity\SecureStorageKeyCustodian;

/**
 * @link ../../.docs/features/desktop/architecture.md#what-safestorage-is-worth-on-linux
 */

// The custody seam spent a release registered and unwired: the contract, the
// pass-through default and both platform adapters all existed, and the two
// lines that point the contract at an adapter were the whole of what "wired"
// meant. Deleting either is a one-line change that no other test notices —
// every suite runs on the pass-through default, so the tree stays green while
// the unlocked key goes back to following the session on a real device.

/**
 * @return array<string, string> shell => the provider's source
 */
function shellProviderSources(): array
{
    $sources = [];

    foreach (['desktop' => 'Desktop', 'mobile' => 'Mobile'] as $shell => $module) {
        $path = base_path("Modules/{$module}/Providers/{$module}ServiceProvider.php");

        expect($path)->toBeReadableFile();

        $sources[$shell] = (string) file_get_contents($path);
    }

    return $sources;
}

it('points the custody contract at the desktop adapter inside the bundle', function (): void {
    $source = shellProviderSources()['desktop'];

    expect(PatternScan::matches(
        '/singleton\(\s*KeyCustodian::class,\s*DesktopKeyCustodian::class\s*,?\s*\)/',
        $source,
    ))->toBeTrue('DesktopServiceProvider no longer binds KeyCustodian to DesktopKeyCustodian: the '
        .'unlocked data key is back in the session on every desktop bundle.');

    // Bound inside the gate on purpose: local dev, CI and the self-hosted web
    // app have no shell to answer, and a binding outside it would send every
    // one of them through an HTTP call to a process that is not there.
    expect(PatternScan::matches(
        "/nativephp-internal\.running'\) === true\)/",
        $source,
    ))->toBeTrue('The desktop bundle gate is gone; the custody binding is no longer scoped to a real shell.');
});

it('points the custody contract at the mobile adapter on device', function (): void {
    expect(PatternScan::matches(
        '/singleton\(\s*KeyCustodian::class,\s*SecureStorageKeyCustodian::class\s*,?\s*\)/',
        shellProviderSources()['mobile'],
    ))->toBeTrue('MobileServiceProvider no longer binds KeyCustodian to SecureStorageKeyCustodian: the '
        .'unlocked data key is back in the sessions table on every phone.');
});

it('resolves the pass-through custodian everywhere else', function (): void {
    expect(app(KeyCustodian::class))->toBeInstanceOf(NullKeyCustodian::class);
});

// An adapter inheriting the answer instead of giving one would report whatever
// the pass-through reports, which is the shape of the original defect: a seam
// that looks bound and answers for somebody else's platform.
it('has each platform adapter answering the custody question itself', function (string $adapter): void {
    $declaring = (new ReflectionClass($adapter))->getMethod('custody')->getDeclaringClass()->getName();

    expect($declaring)->toBe($adapter);
})->with([DesktopKeyCustodian::class, SecureStorageKeyCustodian::class, NullKeyCustodian::class]);

it('treats only operating-system custody as protection at rest', function (): void {
    $protecting = array_values(array_filter(
        KeyCustody::cases(),
        static fn (KeyCustody $custody): bool => $custody->protectsAtRest(),
    ));

    expect($protecting)->toBe([KeyCustody::OperatingSystem]);
});
