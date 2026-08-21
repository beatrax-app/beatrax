<?php

declare(strict_types=1);

use Modules\Sync\Internal\Crypto\GdkEpochId;

// Epoch ids used to be a local counter — first 1, then max(held) + 1 — which is
// unique only among the epochs one device happens to hold, so a standalone phone
// and a twice-rotated desktop both arrived at "epoch 3" holding different keys.
// The desktop then dropped everything the phone wrote as gdk_decrypt_failed.

it('never mints an id the keyring already holds', function (): void {
    $held = [1, 2, 3];

    for ($attempt = 0; $attempt < 200; $attempt++) {
        expect($held)->not->toContain(GdkEpochId::mint($held));
    }
});

it('does not hand two independent devices the same id', function (): void {
    // Each device mints from its OWN keyring, knowing nothing of the other.
    // Under the counter this produced 1 and 1, then 2 and 2, and so on — a
    // guaranteed collision rather than an unlucky one.
    $first = [];
    $second = [];

    for ($rotation = 0; $rotation < 50; $rotation++) {
        $first[] = GdkEpochId::mint($first);
        $second[] = GdkEpochId::mint($second);
    }

    expect(array_intersect($first, $second))->toBe([], 'two devices minted the same epoch id');
});

it('stays inside the range that survives a JSON round-trip', function (): void {
    // Epoch ids travel inside wrap payloads. One that came back rounded would
    // name a key nobody holds, which is the same failure by another route.
    for ($attempt = 0; $attempt < 200; $attempt++) {
        $id = GdkEpochId::mint([]);

        expect($id)->toBeGreaterThan(0)
            ->and($id)->toBeLessThanOrEqual(GdkEpochId::MAX);

        $roundTripped = json_decode(json_encode(['epoch' => $id], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        expect($roundTripped['epoch'])->toBe($id, 'the id did not survive a JSON round-trip exactly');
    }
});
