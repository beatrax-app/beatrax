<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Public\Services\DependentRowCascade;

uses(RefreshDatabase::class);

// No foreign key cascades any more, so the database refuses a parent delete
// while an owned row is still there. A child in a table that never leaves the
// device is one the ORIGIN cannot name: it has never heard of the row, so no
// operation it writes can clear it and the receiver is the only side that can.
// Unswept, such a child refuses the peer's tombstone for good.

/**
 * @return list<string>
 */
function tombstoneBlockerTablesThatTravel(): array
{
    $reflected = new ReflectionClass(OpLogBackfiller::class);
    /** @var list<string> $neverOnTheWire */
    $neverOnTheWire = $reflected->getConstant('DEVICE_LOCAL_TABLES');

    $travelling = [];

    foreach (array_keys(app(MergeRulesRegistry::class)->rules()) as $table) {
        if (! in_array((string) $table, $neverOnTheWire, true)) {
            $travelling[] = (string) $table;
        }
    }

    return $travelling;
}

/**
 * @return list<array{child: string, column: string, parent: string}>
 */
function tombstoneBlockerForeignKeys(): array
{
    $keys = [];

    foreach (Schema::getTableListing() as $listed) {
        $child = str_contains($listed, '.') ? substr($listed, (int) strrpos($listed, '.') + 1) : $listed;

        foreach (Schema::getForeignKeys($child) as $foreignKey) {
            $column = $foreignKey['columns'][0] ?? '';
            $parent = $foreignKey['foreign_table'];

            // A key that nulls itself never refuses anything, and the owner
            // column is swept from the live schema by the user-scoped purge
            // rather than classified here.
            if (($foreignKey['on_delete'] ?? '') === 'set null' || ($column === 'user_id' && $parent === 'users')) {
                continue;
            }

            $keys[] = ['child' => $child, 'column' => (string) $column, 'parent' => (string) $parent];
        }
    }

    return $keys;
}

// Every table a peer's tombstone can reach: the ones that travel, plus the
// device-local rows hanging off them, plus the device-local rows hanging off
// THOSE — a grandchild refuses its parent's delete just as flatly, and the
// parent's refusal is then handed back up.
/**
 * @param  list<array{child: string, column: string, parent: string}>  $keys
 * @param  list<string>  $travelling
 * @return list<array{child: string, column: string, parent: string}>
 */
function tombstoneBlockerEdges(array $keys, array $travelling): array
{
    $reachable = $travelling;
    $edges = [];
    $seen = [];

    for ($depth = 0; $depth < count($keys) && $reachable !== []; $depth++) {
        $next = [];

        foreach ($keys as $key) {
            if (in_array($key['child'], $travelling, true) || ! in_array($key['parent'], $reachable, true)) {
                continue;
            }

            $edge = $key['child'].'.'.$key['column'];
            if (isset($seen[$edge])) {
                continue;
            }

            $seen[$edge] = true;
            $edges[] = $key;
            $next[] = $key['child'];
        }

        $reachable = $next;
    }

    return $edges;
}

it('classifies every device-local row a peer tombstone would have to clear', function (): void {
    $travelling = tombstoneBlockerTablesThatTravel();
    $keys = tombstoneBlockerForeignKeys();
    $edges = tombstoneBlockerEdges($keys, $travelling);

    // The denominators, before any verdict is read off them: a walk narrowed
    // to nothing reports a clean tree, which is a green light nobody earned.
    expect(count($travelling))->toBeGreaterThanOrEqual(30)
        ->and(count($keys))->toBeGreaterThanOrEqual(40)
        ->and(count($edges))->toBeGreaterThanOrEqual(12);

    $owned = DependentRowCascade::ownedBy();
    $unswept = [];

    foreach ($edges as $edge) {
        if (! in_array($edge['child'].'.'.$edge['column'], $owned[$edge['parent']] ?? [], true)) {
            $unswept[] = $edge['child'].'.'.$edge['column'].' -> '.$edge['parent'];
        }
    }

    sort($unswept);

    expect($unswept)->toBe([], implode("\n", [
        'These foreign keys let a row that never leaves this device refuse a peer',
        'tombstone. The origin cannot name the row in an operation — it has never',
        'heard of it — so nothing that arrives later can unblock the delete:',
        ...$unswept,
        '',
        'Add each to DependentRowCascade::OWNED_BY under the parent it belongs to,',
        'so the arrival sweep clears it. A child that must OUTLIVE its parent needs',
        'a nullable column and ON DELETE SET NULL, plus an entry in NOT_OWNED.',
    ]));
});

// The classification above is inert unless the arrival path reads it. The
// local delete path has run this cascade since the clauses were dropped; the
// path that applies a PEER's tombstone deleted the parent row directly.
function tombstoneBlockerSweepPrecedesTheHold(string $source): bool
{
    $loop = PatternScan::firstWithOffsets('/foreach \(\$refused as \$blocked\) \{/', $source);
    $sweep = PatternScan::firstWithOffsets('/->clearDeviceLocalChildren\(/', $source);
    $hold = PatternScan::firstWithOffsets('/\$this->recordBlockedDelete\(/', $source);

    if ($loop === [] || $sweep === [] || $hold === []) {
        return false;
    }

    return $loop[0][1] < $sweep[0][1] && $sweep[0][1] < $hold[0][1];
}

it('clears what only this device has before it files the tombstone as blocked', function (): void {
    $path = base_path('Modules/Sync/Internal/Merge/OpLogEntryApplier.php');
    $source = (string) file_get_contents($path);

    expect($source)->toContain('public function applyDeletions(')
        ->and($source)->toContain('private function recordBlockedDelete(');

    expect(tombstoneBlockerSweepPrecedesTheHold($source))->toBeTrue(implode("\n", [
        'applyDeletions() files a refused tombstone as delete_blocked_by_reference',
        'without first clearing the rows this device derived behind the parent.',
        'Those rows exist on no other device, so no operation will ever arrive to',
        'remove them and the hold stands for the life of the install.',
        '',
        'Call DependentRowCascade::clearDeviceLocalChildren() on the parent in the',
        'retry pass, before recordBlockedDelete().',
    ]));
});

// The scanner's own denominator: a matcher that answers true for everything,
// or false for everything, would pass the rule above without reading it.
// Assembled here rather than read off a file so neither answer is borrowed.
it('reads the order it claims to read', function (): void {
    $loop = 'foreach ($refused as $blocked) {';
    $sweep = '            $this->cascade->clearDeviceLocalChildren($t, $pk, $u);';
    $hold = '            $this->recordBlockedDelete($t, $pk, $tomb, $now);';

    expect(tombstoneBlockerSweepPrecedesTheHold($loop."\n".$sweep."\n".$hold))->toBeTrue()
        ->and(tombstoneBlockerSweepPrecedesTheHold($loop."\n".$hold))->toBeFalse()
        ->and(tombstoneBlockerSweepPrecedesTheHold($loop."\n".$hold."\n".$sweep))->toBeFalse()
        ->and(tombstoneBlockerSweepPrecedesTheHold($sweep."\n".$hold))->toBeFalse();
});
