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

        expect($path)->toBeReadableFile($module.'ServiceProvider.php is not readable, so the bindings this file asserts about were never opened.');

        $source = (string) file_get_contents($path);

        // Read before any pattern: an empty read matches nothing, and every
        // rule below is a pattern that must match. A provider that came back
        // blank would fail them all with the wrong reason.
        expect(strlen($source))->toBeGreaterThan(
            200,
            $module.'ServiceProvider.php read back '.strlen($source).' bytes, which is too few to be a provider.'
        );

        $sources[$shell] = $source;
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

// "Everywhere else" is what the two gates above leave, and this asserts it
// where a test can: the container resolves the pass-through when no shell has
// answered. Local dev, CI and the self-hosted web app are that same case.
it('resolves the pass-through custodian when no shell has bound one', function (): void {
    expect(app(KeyCustodian::class))->toBeInstanceOf(
        NullKeyCustodian::class,
        'Outside a bundle the custody contract must resolve to the pass-through. A different binding here '
        .'means the suite has been running against a platform adapter, and every custody assertion in it '
        .'has been answering for somebody else\'s platform.'
    );
});

// An adapter inheriting the answer instead of giving one would report whatever
// the pass-through reports, which is the shape of the original defect: a seam
// that looks bound and answers for somebody else's platform.
it('has each platform adapter answering the custody question itself', function (string $adapter): void {
    $declaring = (new ReflectionClass($adapter))->getMethod('custody')->getDeclaringClass()->getName();

    expect($declaring)->toBe(
        $adapter,
        $adapter.'::custody() is inherited from '.$declaring.', so it answers for whichever platform that '
        .'class was written for rather than for its own.'
    );
})->with([DesktopKeyCustodian::class, SecureStorageKeyCustodian::class, NullKeyCustodian::class]);

it('treats only operating-system custody as protection at rest', function (): void {
    $cases = KeyCustody::cases();

    // Read before the verdict: with one case the filter below cannot tell a
    // considered answer from an enum that has lost its other arms.
    expect(count($cases))->toBeGreaterThan(
        1,
        'KeyCustody declares '.count($cases).' cases, so "only operating-system custody" is a claim about '
        .'a set with nothing to exclude.'
    );

    $protecting = array_values(array_filter(
        $cases,
        static fn (KeyCustody $custody): bool => $custody->protectsAtRest(),
    ));

    expect($protecting)->toBe(
        [KeyCustody::OperatingSystem],
        'Only custody held by the operating system protects the key at rest. Another case answering true '
        .'here tells the reader their data key is protected when it is following the session.'
    );
});
