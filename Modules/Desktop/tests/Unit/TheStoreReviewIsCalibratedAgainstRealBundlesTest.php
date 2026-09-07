<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Boot\MacBundleReview;

// Rules invented from documentation agree with themselves. These cases are
// the only ones that can disagree with them: bundles Apple actually shipped
// through the store have to pass, and one distributed outside it has to fail.

// The fixture is a recording, not an invention — every entitlement and every
// nested executable in it was read off a bundle on a real machine, by the
// same reader the command uses. Refresh it with the generator named in
// .docs/runbooks/store-submission.md when Apple changes what it accepts.
function observedMacBundles(): array
{
    $path = __DIR__.'/../Fixtures/mac-bundles-observed.json';

    /** @var array{recorded_on: string, bundles: list<array<string, mixed>>} $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $decoded['bundles'];
}

function observedBundlesFrom(bool $store): array
{
    return array_values(array_filter(
        observedMacBundles(),
        static fn (array $bundle): bool => $bundle['from_the_store'] === $store,
    ));
}

it('recorded both a store bundle and one from outside it', function (): void {
    expect(observedBundlesFrom(true))->not->toBe([], 'without a store bundle there is nothing to calibrate against');
    expect(observedBundlesFrom(false))->not->toBe([], 'without one from outside, rules that refuse nothing would read as calibrated');
});

it('refuses nothing in a bundle Apple shipped through the store', function (): void {
    $review = new MacBundleReview;

    foreach (observedBundlesFrom(true) as $bundle) {
        expect($review->refusals($bundle['app_entitlements'], $bundle['children']))->toBe(
            [],
            $bundle['name'].' came from the Mac App Store, so a rule that refuses it is wrong about the store rather than about the app',
        );
    }
});

it('refuses an Electron app distributed outside the store', function (): void {
    $review = new MacBundleReview;

    foreach (observedBundlesFrom(false) as $bundle) {
        expect($review->refusals($bundle['app_entitlements'], $bundle['children']))->not->toBe(
            [],
            $bundle['name'].' is distributed outside the store; rules that find nothing in it are finding nothing at all',
        );
    }
});

// The case above only asks that SOMETHING was refused, and something always
// is. This asks that what was refused is about the store lane — an app the
// store would not sandbox, or a key it does not take — rather than an
// incidental signature or layout finding that would survive the rules being
// wrong about everything that matters.
it('refuses the outside bundle for a reason the store lane is about', function (): void {
    $review = new MacBundleReview;
    $storeReasons = [...array_keys(MacBundleReview::REFUSED_ENTITLEMENTS), 'not sandboxed'];

    foreach (observedBundlesFrom(false) as $bundle) {
        $refusals = implode("\n", $review->refusals($bundle['app_entitlements'], $bundle['children']));

        $named = array_values(array_filter(
            $storeReasons,
            static fn (string $reason): bool => str_contains($refusals, $reason),
        ));

        expect($named)->not->toBe([], $bundle['name'].' was refused, but for nothing the store lane turns on');
    }
});
