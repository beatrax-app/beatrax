<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/architecture/measuring-write-cost.md
 */

// The desktop bundle launches PHP with max_execution_time=120, and the lift in
// CoreServiceProvider is guarded by runningInConsole(). A web request keeps the
// ceiling, and an expiring limit is a FATAL: no catch runs, the open
// transaction rolls back whole, and the tree has already paid for that once --
// 16,184 op-log entries written and none persisted.

// Named per ENTRY POINT rather than per file, because it is one method that
// runs long and the class around it may hold a dozen that do not.
const CEILING_LIFTING_ENTRY_POINTS = [
    'Modules/Core/Public/Services/EncryptionMigrationService.php' => [
        'method' => 'migrate',
        'walks' => 'the whole ledger, every projection table and a counterparty backfill, inside one transaction',
    ],
    'Modules/Core/Public/Services/RestoreEncryptedBackup.php' => [
        'method' => '__invoke',
        'walks' => 'a whole-database decrypt, schema catch-up and transplant, reached from a Livewire request',
    ],
    'Modules/Onboarding/Internal/Http/Livewire/Steps/FirstImportStep.php' => [
        'method' => 'commitEverything',
        'walks' => 'the largest import this account will ever take, with every committed chunk demoted to a savepoint by the transaction around it',
    ],
];

// The method's own body, so a lift somewhere else in the class does not answer
// for it. Brace-counted from the signature rather than matched, because the
// bodies here nest closures several deep.
function ceilingBodyOf(string $source, string $method): ?string
{
    $at = strpos($source, 'function '.$method.'(');

    if ($at === false) {
        return null;
    }

    $open = strpos($source, '{', $at);

    if ($open === false) {
        return null;
    }

    $depth = 0;

    for ($cursor = $open, $length = strlen($source); $cursor < $length; $cursor++) {
        $depth += (int) ($source[$cursor] === '{') - (int) ($source[$cursor] === '}');

        if ($depth === 0) {
            return substr($source, $open, $cursor - $open + 1);
        }
    }

    return null;
}

it('lifts the execution ceiling in every web path that walks the ledger', function (): void {
    $missing = [];

    foreach (CEILING_LIFTING_ENTRY_POINTS as $relative => $entry) {
        $path = RepoTree::root().'/'.$relative;

        if (! is_file($path)) {
            $missing[] = $relative.' is pinned and no longer exists';

            continue;
        }

        $body = ceilingBodyOf((string) file_get_contents($path), $entry['method']);

        if ($body === null) {
            $missing[] = $relative.' no longer declares '.$entry['method'].'()';

            continue;
        }

        if (! PatternScan::matches('/set_time_limit\(\s*0\s*\)/', $body)) {
            $missing[] = $relative.'::'.$entry['method'].'() walks '.$entry['walks']
                .', and no longer lifts the limit';
        }
    }

    expect($missing)->toBe([], implode("\n", [
        'These run past 120 seconds on a slow disk, and the expiry is a fatal rather than a',
        'Throwable — so the catch that would have undone the work never runs:',
        ...$missing,
        '',
        'Call set_time_limit(0) at the top of the method, as SampleDataCard::load() does.',
    ]));
});

// The rule claims three entry points and not the tree, and saying which is the
// difference between a bounded rule and one that has quietly stopped reading.
it('says how narrow it is, and reads a body that does not lift', function (): void {
    expect(CEILING_LIFTING_ENTRY_POINTS)->toHaveCount(3);

    // 22 more shipped classes walk a table without lifting. Most are reached
    // only from a console command or a queued job, where the limit is already
    // lifted, and telling those apart needs call-graph reachability rather
    // than a text scan. That is why this pins rather than sweeps.
    $planted = '<?php class X { public function lifts() { set_time_limit(0); return 1; } '
        .'public function doesNot() { $rows = []; return $rows; } }';

    expect(ceilingBodyOf($planted, 'lifts'))->toContain('set_time_limit(0)')
        ->and(ceilingBodyOf($planted, 'doesNot'))->not->toContain('set_time_limit')
        ->and(ceilingBodyOf($planted, 'notThere'))->toBeNull();
});
