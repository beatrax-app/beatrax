<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// No budget export carries a time of day, so a promoted booked_at is the
// posting date and nothing else. Two same-day rows are kept off one
// fingerprint by the ordinal's own column; booked_at used to carry that as a
// seconds offset, and a screen drawing it named a time no export contained.

beforeEach(function (): void {
    $this->sourceDayUser = User::create([
        'username' => 'source-day-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->sourceDayDb = app(DatabaseManager::class);
});

function sourceDayPromoteYnab4(User $user, string $fixture = 'v1'): int
{
    $run = app(StartMigrationRun::class)->__invoke(
        $user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir($fixture),
        'Beatrax Test Budget.zip',
    );

    app(ConfirmMigration::class)->__invoke($run->id, $user);

    return $run->id;
}

/**
 * @return array<string, string> the staged posting day, keyed by source_ref
 */
function sourceDayStagedDays(DatabaseManager $db, int $runId, User $user): array
{
    $days = [];

    $rows = $db->connection()->table('migration_staging_transactions')
        ->where('user_id', $user->id)
        ->where('migration_run_id', $runId)
        ->get(['source_external_id', 'posted_at']);

    foreach ($rows as $row) {
        $days['migration:ynab4:'.$row->source_external_id] = substr((string) $row->posted_at, 0, 10);
    }

    return $days;
}

it('posts every promoted row on the exact day its export stated', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->sourceDayDb;

    $runId = sourceDayPromoteYnab4($this->sourceDayUser);
    $stagedDays = sourceDayStagedDays($db, $runId, $this->sourceDayUser);

    // Counted first: no staged row means no promoted row to compare against,
    // and an empty comparison is the answer a correct import gives too.
    expect($stagedDays)->not->toBeEmpty();

    // Read raw, not through the model: the date cast normalises a stored time
    // away on the way out, which is exactly what would hide the defect.
    $promoted = $db->connection()->table('transactions')
        ->where('user_id', $this->sourceDayUser->id)
        ->get(['source_ref', 'posted_at', 'value_date', 'booked_at']);

    expect($promoted->count())->toBeGreaterThan(0);

    $wrong = [];
    foreach ($promoted as $row) {
        $sourceRef = (string) $row->source_ref;
        $stated = $stagedDays[$sourceRef] ?? null;
        if ($stated === null) {
            continue;
        }

        $postedAt = (string) $row->posted_at;
        if ($postedAt !== $stated) {
            $wrong[] = $sourceRef.': posted_at '.$postedAt.', export said '.$stated;
        }
        if ((string) $row->value_date !== $stated) {
            $wrong[] = $sourceRef.': value_date '.$row->value_date.', export said '.$stated;
        }
    }

    expect($wrong)->toBe(
        [],
        'A migrated row is posted on the day the export named it, to the character — the internal '
        ."ordering offset belongs on booked_at and reaches no user-facing date:\n  "
        .implode("\n  ", $wrong),
    );
});

// The `twins` register holds the case the offset was written for: two Albert
// Heijn rows on 15 January, both 45,00, differing only by a memo the
// fingerprint does not read. Every other fixture leaves the offset at zero, so
// running this against one of those asserts that nothing spilled out of a day
// nothing was ever moved within.
it('states no time of day its source did not, and still separates two same-day rows', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->sourceDayDb;

    sourceDayPromoteYnab4($this->sourceDayUser, 'twins');

    $promoted = $db->connection()->table('transactions')
        ->where('user_id', $this->sourceDayUser->id)
        ->get(['source_ref', 'posted_at', 'booked_at', 'occurrence_ordinal']);

    expect($promoted->count())->toBeGreaterThan(0);

    $invented = [];
    foreach ($promoted as $row) {
        $bookedAt = (string) $row->booked_at;
        $postedAt = (string) $row->posted_at;

        if ($bookedAt !== $postedAt.' 00:00:00') {
            $invented[] = (string) $row->source_ref.': booked_at '.$bookedAt.', posted_at '.$postedAt;
        }
    }

    expect($invented)->toBe(
        [],
        'A promoted booked_at is the posting date at midnight. Anything else is a time of day the '
        ."reader's own export never carried, drawn on the detail screen as though it had:\n  "
        .implode("\n  ", $invented),
    );

    // And the separation still has to work: both trips survive as two rows,
    // told apart by the ordinal rather than by the clock.
    $twins = $db->connection()->table('transactions')
        ->where('user_id', $this->sourceDayUser->id)
        ->whereDate('posted_at', '2026-01-15')
        ->where('amount_minor', -4500)
        ->get(['occurrence_ordinal']);

    expect($twins)->toHaveCount(2);
    expect($twins->pluck('occurrence_ordinal')->map(fn (mixed $v): int => (int) $v)->unique())
        ->toHaveCount(2);
});

it('draws no second date on a migrated row, because there is not one', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->sourceDayDb;

    sourceDayPromoteYnab4($this->sourceDayUser);

    $transactionId = $db->connection()->table('transactions')
        ->where('user_id', $this->sourceDayUser->id)
        ->orderBy('posted_at')
        ->value('id');

    expect($transactionId)->not->toBeNull();

    $response = $this->actingAs($this->sourceDayUser)
        ->get(route('transactions.show', ['transactionId' => $transactionId]));

    $response->assertOk();
    $response->assertDontSee('tx-detail-booked-at', escape: false);
});
