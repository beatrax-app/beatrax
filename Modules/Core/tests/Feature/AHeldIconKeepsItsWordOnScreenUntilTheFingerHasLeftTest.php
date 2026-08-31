<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Helpers\CssRule;

// x-core::emoji-action is a picture with no word beside it, and a hold is the
// only way a finger reads that word. Twice now a handler wired for something
// else has torn the tip down mid-hold, so the timings measured on a device are
// pinned here rather than left to a comment.

/** @return array<string,bool> */
function emojiActionHoldTimeline(): array
{
    $harness = base_path('Modules/Core/tests/Fixtures/emoji-action-hold-harness.mjs');
    expect($harness)->toBeReadableFile();

    $process = new Process(['node', $harness], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    /** @var array<string,bool> $decoded */
    $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('leaves the tip up for the whole hold, past the callout the OS raises mid-press', function (): void {
    $seen = emojiActionHoldTimeline();

    expect($seen['shownAfterHold'])->toBeTrue('the tip never appeared 450ms into the hold')
        ->and($seen['calloutPrevented'])->toBeTrue('the OS callout was left to cover the tip')
        ->and($seen['shownAfterCallout'])->toBeTrue('the callout blanked the tip 130ms after it appeared')
        ->and($seen['shownWhileStillHeld'])->toBeTrue('the tip was gone while the finger was still down');
});

it('keeps the word readable after the finger lifts off it', function (): void {
    $seen = emojiActionHoldTimeline();

    expect($seen['shownJustAfterRelease'])->toBeTrue('the tip died with the finger that was covering it')
        ->and($seen['shownShortlyAfterRelease'])->toBeTrue('the tip did not outlast the release')
        ->and($seen['shownLongAfterRelease'])->toBeFalse('the tip never went away');
});

it('swallows the click a hold ends with, so the row is read and not acted on', function (): void {
    $seen = emojiActionHoldTimeline();

    expect($seen['swallowArmed'])->toBeTrue('the release left the guard disarmed')
        ->and($seen['holdClickSwallowed'])->toBeTrue('the hold reached the action it was only labelling');
});

it('lets a short tap through, including one straight after a hold', function (): void {
    $seen = emojiActionHoldTimeline();

    expect($seen['tapShowsNoTip'])->toBeTrue('a tap raised the hold tip')
        ->and($seen['tapClickReachesAction'])->toBeTrue('a tap was swallowed by a guard armed for an earlier hold');
});

it('leaves a mouse the title and the context menu it already had', function (): void {
    $seen = emojiActionHoldTimeline();

    expect($seen['mouseArmsNothing'])->toBeTrue('a mouse press armed the touch hold')
        ->and($seen['mouseKeepsContextMenu'])->toBeTrue('right-click lost the browser context menu');
});

it('treats a press that travels as the scroll it is', function (): void {
    $seen = emojiActionHoldTimeline();

    expect($seen['driftHidesTip'])->toBeTrue('a scroll off the mark left the tip up')
        ->and($seen['driftClickReachesAction'])->toBeTrue('a scroll left the guard armed');
});

// A thumb is on the mark when the tip appears, so the tip is read around the
// finger that summoned it. Measured in a headless engine against the built
// stylesheet: the widest caption in the product, Ukrainian "Перейменувати",
// is 141.1px at this size and clamps to 225px (60vw) on a 375px screen.
it('sizes the tip to be read past a thumb rather than as a hover tooltip', function (): void {
    $tip = CssRule::blockFor((string) file_get_contents(base_path('resources/css/app.css')), '.emoji-action__tip {');

    expect($tip)->not->toBe('', 'No rule in app.css declares .emoji-action__tip.')
        ->and($tip)->toContain('font-size: var(--text-base);')
        ->and($tip)->not->toContain('var(--text-xs)')
        ->and($tip)->toContain('max-width: 60vw;');
});

// The thumb covers the mark AND the gap immediately above it, so the tip's
// clearance is its own number and not the screen-edge inset it once reused.
it('lifts the tip further off the mark than it insets from the screen edge', function (): void {
    $source = (string) file_get_contents(base_path('resources/js/emoji-action-hold.js'));

    preg_match('/const LIFT_PX = (\\d+);/', $source, $lift);
    preg_match('/const GAP_PX = (\\d+);/', $source, $gap);

    expect($lift[1] ?? null)->not->toBeNull('emoji-action-hold.js declares no LIFT_PX.')
        ->and($gap[1] ?? null)->not->toBeNull('emoji-action-hold.js declares no GAP_PX.')
        ->and((int) $lift[1])->toBeGreaterThan((int) $gap[1]);
});

// The tip teleports out of its own stacking context to escape the pots list's
// overflow and the calendar panel's transform, so it has to out-rank every
// overlay it can be summoned inside. At the app scale's top of 80 it rendered
// behind the command palette: real geometry, nothing painted.
it('ranks the tip above every overlay a mark can sit inside', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    preg_match('/z-index: (\\d+);/', CssRule::blockFor($css, '.emoji-action__tip {'), $tip);
    expect($tip[1] ?? null)->not->toBeNull('.emoji-action__tip declares no z-index.');

    $overlays = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'), RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($walk as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getPathname(), '.blade.php')) {
            continue;
        }
        if (preg_match_all('/z-\\[(\\d+)\\]/', (string) file_get_contents($file->getPathname()), $m) > 0) {
            $overlays = array_merge($overlays, array_map('intval', $m[1]));
        }
    }

    expect($overlays)->not->toBeEmpty('No z-[N] overlay utilities found — this guard has stopped reading.')
        ->and((int) $tip[1])->toBeGreaterThan(max($overlays));
});

// The veil hides money on an unattended screen, so nothing renders over it.
it('keeps the tip under the privacy veil', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    preg_match('/z-index: (\\d+);/', CssRule::blockFor($css, '.emoji-action__tip {'), $tip);

    expect((int) $tip[1])->toBeLessThan(2147483000);
});
