<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

/**
 * @link ../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */

/**
 * Every relay drain in this source and the receiver it was asked of. The
 * receiver is what the rule is about: a file naming `->confirm(` somewhere is
 * not the same claim as the client that drained being the client that confirms,
 * and the second is the one a re-download depends on.
 *
 * @return list<string> the receivers this source drains
 */
function drainedRowReceivers(string $source): array
{
    $stripped = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    if (! PatternScan::matches('/\bRelayClient\b/', $stripped)) {
        return [];
    }

    return array_values(array_unique(PatternScan::all('/(\$[\w\[\]\'">-]*?)->drain\(/', $stripped)[1]));
}

/**
 * The subset of those receivers the same source never confirms a row back to.
 *
 * @return list<string>
 */
function drainedRowReceiversLeftStanding(string $source): array
{
    $stripped = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    return array_values(array_filter(
        drainedRowReceivers($source),
        static fn (string $receiver): bool => ! PatternScan::matches(
            '/'.preg_quote($receiver, '/').'->confirm\(/',
            $stripped,
        ),
    ));
}

// A relay drain is not destructive: the blob stays in the recipient's mailbox
// until a confirm deletes it. A reader that never confirms re-downloads the
// whole mailbox on every tick until the TTL retires it, and — because the drain
// call itself succeeded — reports the leg as reached while nothing was applied.
// Both halves of that were live on the phone's off-LAN leg.
it('confirms rows back to the same relay client every drain was asked of', function (): void {
    $files = BackendSourceFiles::all();

    expect(count($files))->toBeGreaterThan(
        1000,
        'The walk opened '.count($files).' backend files, which is too few to have read the tree at all.',
    );

    $drains = 0;
    $offenders = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $relative = str_replace(base_path().'/', '', $path);

        $drains += count(drainedRowReceivers($source));

        foreach (drainedRowReceiversLeftStanding($source) as $receiver) {
            $offenders[] = $relative.' drains '.$receiver.' and never confirms to it';
        }
    }

    // Two classes drain a relay mailbox today. A walk that found none of them
    // reports a clean tree, which is the answer a correct one gives too.
    expect($drains)->toBeGreaterThanOrEqual(
        2,
        'The walk found '.$drains.' relay drains, so it stopped reading the tree rather than finding it clean.',
    );

    expect($offenders)->toBe(
        [],
        "A class that drains a relay mailbox and never confirms back to the SAME client leaves every blob standing\n".
        "until the TTL, and re-downloads all of them on the next tick. Confirm what this leg is TERMINALLY the\n".
        "reader of and leave another protocol's frames for their own poll — see PairingFrameCourier::applyDrainedRow().\n  ".
        implode("\n  ", $offenders),
    );
});

// The reader is the whole of the verdict, so it is driven over a drain nobody
// confirms, one confirmed on a different client — which is the shape a
// file-wide search could not tell from the first — and one done properly.
it('sees a drain nobody confirms, and one confirmed on somebody else', function (): void {
    $stranded = '<?php class C { public function __construct(private RelayClient $c) {} '
        .'public function pull(): void { $rows = $this->c->drain($id, $t); } }';
    $elsewhere = '<?php class C { public function __construct(private RelayClient $c, private Other $o) {} '
        .'public function pull(): void { $rows = $this->c->drain($id, $t); $this->o->confirm($id); } }';
    $confirmed = '<?php class C { public function __construct(private RelayClient $c) {} '
        .'public function pull(): void { $rows = $this->c->drain($id, $t); $this->c->confirm($id, $t); } }';
    $unrelated = '<?php class C { public function pull(): void { $rows = $this->queue->drain($id); } }';

    expect(drainedRowReceiversLeftStanding($stranded))->toBe(['$this->c'], 'A drain nothing confirms went unread.');
    expect(drainedRowReceiversLeftStanding($elsewhere))->toBe(
        ['$this->c'],
        'A confirm on a different client was read as the drained client confirming, which is the gap a file-wide search leaves.',
    );
    expect(drainedRowReceiversLeftStanding($confirmed))->toBe([], 'A drain confirmed back to its own client was reported.');
    expect(drainedRowReceiversLeftStanding($unrelated))->toBe([], 'A drain of something that is not a relay mailbox was reported.');

    // Comments name the seam on purpose, so they are dropped before anything is
    // read — a docblock describing the confirm would otherwise answer for it.
    $commented = '<?php class C { /** confirms via $this->c->confirm() */ public function __construct(private RelayClient $c) {} '
        .'public function pull(): void { $rows = $this->c->drain($id, $t); } }';

    expect(drainedRowReceiversLeftStanding($commented))->toBe(['$this->c'], 'A confirm named only in prose answered for a confirm nobody makes.');
});
