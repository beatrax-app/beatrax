<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// No budget export carries a time of day, so promotion bolts a deterministic
// per-row offset onto booked_at to keep two same-day rows off one fingerprint.
// That offset is a sort key and nothing else: the day it lands on has to stay
// the day the file said, or a screen that draws booked_at names a date the
// reader's own export never contained.

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
it('keeps the ordering offset inside the day it orders', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->sourceDayDb;

    sourceDayPromoteYnab4($this->sourceDayUser, 'twins');

    $promoted = $db->connection()->table('transactions')
        ->where('user_id', $this->sourceDayUser->id)
        ->get(['source_ref', 'posted_at', 'booked_at']);

    expect($promoted->count())->toBeGreaterThan(0);

    $spilled = [];
    $offsetRows = 0;

    foreach ($promoted as $row) {
        $bookedAt = (string) $row->booked_at;
        $postedAt = (string) $row->posted_at;

        if (substr($bookedAt, 0, 10) !== $postedAt) {
            $spilled[] = (string) $row->source_ref.': booked_at '.$bookedAt.', posted_at '.$postedAt;
        }
        if ($bookedAt !== $postedAt.' 00:00:00') {
            $offsetRows++;
        }
    }

    // The offset has to be there, or this is asserting that nothing spilled out
    // of a day nothing was ever moved within.
    expect($offsetRows)->toBeGreaterThan(0);

    // And it has to have done its job: both trips survive as two rows. An
    // offset that separates nothing would let the second collapse onto the
    // first as a duplicate fingerprint and be dropped.
    expect($db->connection()->table('transactions')
        ->where('user_id', $this->sourceDayUser->id)
        ->whereDate('posted_at', '2026-01-15')
        ->where('amount_minor', -4500)
        ->count())->toBe(2);

    expect($spilled)->toBe(
        [],
        'The sub-day offset exists to separate two same-day rows, so it must stay inside that day. '
        .'A booked_at on another day is drawn on the detail screen as a second, real date the '
        ."export never carried:\n  ".implode("\n  ", $spilled),
    );
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
