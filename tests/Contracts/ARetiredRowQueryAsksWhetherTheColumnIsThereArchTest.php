<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\DeviceRegistryQueries;
use Tests\Contracts\Support\RepoTree;

// AQueryOverTheDeviceRegistryDecidesAboutARetiredRow makes every query say which
// reading of a retired row it wants. That rule is right, and it drove
// `self_retired_at` into eight query sites without checking one of them against
// the schema -- which it cannot, because the column IS declared.
// @link ../../.docs/conventions/a-predicate-on-a-column-that-is-not-there.md

// A predicate on a column a migration has not applied HERE does not raise:
// SQLite reads the quoted name as a string literal, so whereNotNull is always
// true and whereNull always false. This is the other half of that rule -- a
// reader mandated to name the column has to ask whether it is there.
const RETIRED_COLUMN = 'self_retired_at';

// The two spellings that count as having asked. The seam asks once for every
// caller; a reader that cannot reach the seam asks for itself.
// The seam counts where it is CALLED. The file that DECLARES it also calls it
// seven times, so it would otherwise exempt itself from the question it exists
// to ask -- and that is the one file where the question has to be asked
// outright.
const RETIRED_COLUMN_ASKED = ['SchemaShape::missingColumns', '->stillADevice('];

const RETIRED_COLUMN_SEAM_DECLARATION = 'function stillADevice(';

// What this does NOT see: the seam's own body. DeviceRegistryQueries collects
// statements naming the table, and stillADevice() takes a Builder someone else
// built, so its one line is invisible here. That line is covered by a test
// instead, which is the right split -- one place, asserted directly.

// Asked per FILE rather than per function, because a class legitimately asks
// once at the entry point its private helpers are reached through. The cost is
// that a class with one guarded reader and one unguarded one passes; the
// alternative is a scanner that has to follow calls, which is a worse trade.

// A scan that matched nothing reports what a clean tree reports. Measured at 9
// statements across 5 files; the floor is below that so an ordinary edit does
// not trip it, and far enough above zero that a broken walk does.
const RETIRED_COLUMN_SITE_FLOOR = 6;

// A WRITE naming an absent column raises `no such column` and stops. It is the
// loud half of this and needs no guard, so an array key is not a site.
function retiredColumnIsRead(string $statement): bool
{
    return PatternScan::matches('/\''.RETIRED_COLUMN.'\'\s*(?!=>)/', $statement)
        && ! PatternScan::matches('/\''.RETIRED_COLUMN.'\'\s*=>/', $statement);
}

/**
 * @return list<array{path: string, function: string}>
 */
function retiredColumnSites(): array
{
    $sites = [];

    foreach (DeviceRegistryQueries::all() as $query) {
        if (str_contains($query['statement'], RETIRED_COLUMN) && retiredColumnIsRead($query['statement'])) {
            $sites[] = ['path' => $query['path'], 'function' => $query['function']];
        }
    }

    return $sites;
}

function retiredColumnFileText(string $path): string
{
    return (string) file_get_contents(RepoTree::root().'/'.$path);
}

it('walks the statements it is about to judge', function (): void {
    expect(count(retiredColumnSites()))->toBeGreaterThanOrEqual(
        RETIRED_COLUMN_SITE_FLOOR,
        'The walk found almost nothing, which a broken scan and a clean tree both look like.',
    );
});

it('asks whether the column is there before deciding on a retired row', function (): void {
    $silent = [];

    foreach (retiredColumnSites() as $site) {
        $text = retiredColumnFileText($site['path']);
        $spellings = str_contains($text, RETIRED_COLUMN_SEAM_DECLARATION)
            ? ['SchemaShape::missingColumns']
            : RETIRED_COLUMN_ASKED;
        $asked = array_any($spellings, static fn (string $spelling): bool => str_contains($text, $spelling));

        if (! $asked) {
            $silent[] = $site['path'].'::'.$site['function'];
        }
    }

    expect($silent)->toBe([], sprintf(
        "These name %s and never ask whether it is on the table. SQLite reads a quoted name matching no column as a string literal, so the clause is silently true or silently false rather than an error:\n  %s\nAsk with SchemaShape::missingColumns() and refuse, or go through DeviceRegistryService::stillADevice().",
        RETIRED_COLUMN,
        implode("\n  ", $silent),
    ));
});
