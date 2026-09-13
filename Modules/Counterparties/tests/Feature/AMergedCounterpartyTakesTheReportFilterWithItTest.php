<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;
use Modules\Counterparties\Public\Contracts\MergesCounterparties;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\JsonRowReferences;

uses(RefreshDatabase::class);

// A fold deletes the absorbed row, and it repoints the three columns that name
// a counterparty by an id a grep can find. A saved report's counterparty filter
// is a list of raw ids inside `definition`, so nothing found it and nothing
// moved it.

// The filter RESTRICTS: ReportAggregator passes it straight to the query as
// counterpartyIds. So the report still runs, still prints a figure, and the
// figure now excludes every transaction the fold just moved to the survivor.

function foldedReportFilterUser(): User
{
    return User::query()->create([
        'username' => 'reportfilter-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function foldedReportFilterCounterparty(DatabaseManager $db, int $userId, string $displayName): int
{
    /** @var CounterpartySlugResolver $slugs */
    $slugs = app(CounterpartySlugResolver::class);

    return (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => $slugs->resolveUnique($userId, $displayName),
        'display_name' => $displayName,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

/** @return array<string, mixed> */
function foldedReportFilterDefinition(int $counterpartyId): array
{
    return [
        'metric' => 'spend',
        'dimension' => 'counterparty',
        'periodPreset' => 'ytd',
        'granularity' => 'monthly',
        'currencyMode' => 'base',
        'viz' => 'table',
        'customFrom' => null,
        'customTo' => null,
        'compare' => false,
        'accounts' => [],
        'categories' => [],
        'counterparties' => [$counterpartyId],
        'amountMin' => null,
        'amountMax' => null,
        'amountDirection' => 'both',
    ];
}

function foldedReportFilterSavedReport(DatabaseManager $db, int $userId, string $name, int $counterpartyId): int
{
    $id = DeviceMintedRowId::mint();

    $db->connection()->table('saved_reports')->insert([
        'id' => $id,
        'user_id' => $userId,
        'name' => $name,
        'definition' => json_encode(foldedReportFilterDefinition($counterpartyId), JSON_THROW_ON_ERROR),
        'pinned' => false,
        'created_at' => '2026-02-01 00:00:00',
        'updated_at' => '2026-02-01 00:00:00',
    ]);

    return $id;
}

/** @return list<int> */
function foldedReportFilterStoredIds(DatabaseManager $db, int $reportId): array
{
    $stored = $db->connection()->table('saved_reports')->where('id', $reportId)->value('definition');
    $decoded = is_string($stored) ? json_decode($stored, true) : null;
    $ids = is_array($decoded) && is_array($decoded['counterparties'] ?? null) ? $decoded['counterparties'] : [];

    /** @var list<int> $list */
    $list = array_values(array_map(intval(...), $ids));

    return $list;
}

beforeEach(function (): void {
    $this->user = foldedReportFilterUser();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $userId = (int) $this->user->id;

    $this->survivorId = foldedReportFilterCounterparty($db, $userId, 'Albert Heijn');
    $this->absorbedId = foldedReportFilterCounterparty($db, $userId, 'AH to go');
    $this->untouchedId = foldedReportFilterCounterparty($db, $userId, 'Jumbo');

    $this->reportId = foldedReportFilterSavedReport($db, $userId, 'Boodschappen bij AH', $this->absorbedId);
    $this->untouchedReportId = foldedReportFilterSavedReport($db, $userId, 'Boodschappen bij Jumbo', $this->untouchedId);
});

// Without this the assertions below would pass on a fold that never ran.
it('absorbs the row the report filters on', function (): void {
    /** @var MergesCounterparties $merge */
    $merge = app(MergesCounterparties::class);

    $merge->fold($this->user, ['AH to go'], 'Albert Heijn');

    expect($this->db->connection()->table('counterparties')->where('id', $this->absorbedId)->exists())
        ->toBeFalse('the fold absorbed nothing, so nothing below is about a removed row')
        ->and(foldedReportFilterStoredIds($this->db, $this->reportId))->not->toBe([]);
});

it('moves the saved report filter onto the surviving counterparty', function (): void {
    /** @var MergesCounterparties $merge */
    $merge = app(MergesCounterparties::class);

    $merge->fold($this->user, ['AH to go'], 'Albert Heijn');

    expect(foldedReportFilterStoredIds($this->db, $this->reportId))->toBe(
        [$this->survivorId],
        'The report still filters on the absorbed id, which no row wears now, so it counts none of the spend the fold just moved to the survivor.',
    );
});

it('leaves a report filtering on a counterparty the fold never touched', function (): void {
    /** @var MergesCounterparties $merge */
    $merge = app(MergesCounterparties::class);

    $merge->fold($this->user, ['AH to go'], 'Albert Heijn');

    expect(foldedReportFilterStoredIds($this->db, $this->untouchedReportId))->toBe([$this->untouchedId]);
});

// The column syncs, so a repoint the peer is never told about leaves the two
// devices filtering on different counterparties.
it('announces the rewritten report so the peer applies the same repoint', function (): void {
    /** @var MergesCounterparties $merge */
    $merge = app(MergesCounterparties::class);

    $result = $merge->fold($this->user, ['AH to go'], 'Albert Heijn');

    $announced = array_values(array_filter(
        $result->events,
        static fn (EntityMutated $event): bool => $event->table === 'saved_reports',
    ));

    expect($announced)->toHaveCount(1)
        ->and($announced[0]->pk)->toBe($this->reportId)
        ->and($announced[0]->mutationType)->toBe('edit')
        ->and($announced[0]->dirtyFields['definition']['counterparties'] ?? null)->toBe([$this->survivorId]);
});

// The repoint is derived from the declaration rather than from a list of
// columns, which is the whole reason the JSON one was missed the first time.

// It is pinned because the table is resolved at runtime: the fold writes
// another module's table through it, and the cross-module raw-write scan in
// BoundaryArchTest reads `table('literal')` and so cannot see this one.
it('repoints every declared site that names a counterparty, and no other', function (): void {
    $sites = (new JsonRowReferences)->sitesNaming('counterparties');

    expect($sites)->toBe([
        ['table' => 'saved_reports', 'column' => 'definition', 'path' => 'counterparties.*'],
    ], implode("\n", [
        'A JSON site naming counterparties was added or removed, so the fold now',
        'writes a different set of tables than the one pinned here.',
        '',
        'Add the crossing to crossModuleRawTableWrites in tests/Contracts/BoundaryArchTest.php',
        'if a new module\'s table is reached, then update this list.',
    ]));
});
