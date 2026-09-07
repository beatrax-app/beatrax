<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Boot\MacBundleInspection;

// Rules invented from documentation agree with themselves. These two cases
// are the only ones that can disagree: an app Apple actually shipped through
// the store has to pass, and an Electron app distributed outside it has to
// fail. The first is what caught a rule that refused a real store app's login
// helper for holding its own sandbox rights.

/** @return list<string> */
function bundlesCarryingAStoreReceipt(): array
{
    $found = [];

    foreach (glob('/Applications/*.app') ?: [] as $bundle) {
        if (is_dir($bundle.'/Contents/_MASReceipt')) {
            $found[] = $bundle;
        }
    }

    return $found;
}

it('refuses nothing in an app Apple shipped through the store', function (): void {
    $bundles = bundlesCarryingAStoreReceipt();

    if ($bundles === []) {
        test()->markTestSkipped('no /Applications bundle carries a _MASReceipt on this machine, so there is nothing to calibrate against');
    }

    $inspection = app(MacBundleInspection::class);

    foreach (array_slice($bundles, 0, 3) as $bundle) {
        expect($inspection->refusals($bundle))->toBe(
            [],
            $bundle.' came from the Mac App Store, so a rule that refuses it is wrong about the store rather than about the app',
        );
    }
});

// The negative control. Without it, rules that refuse nothing at all would
// pass the case above and read as calibrated.
it('refuses an Electron app distributed outside the store', function (): void {
    $outside = array_values(array_filter(
        ['/Applications/Discord.app', '/Applications/Claude.app', '/Applications/Notion.app'],
        static fn (string $bundle): bool => is_dir($bundle) && ! is_dir($bundle.'/Contents/_MASReceipt'),
    ));

    if ($outside === []) {
        test()->markTestSkipped('no non-store Electron app on this machine to use as the negative control');
    }

    expect(app(MacBundleInspection::class)->refusals($outside[0]))
        ->not->toBe([], $outside[0].' is distributed outside the store; rules that find nothing in it are finding nothing at all');
});
