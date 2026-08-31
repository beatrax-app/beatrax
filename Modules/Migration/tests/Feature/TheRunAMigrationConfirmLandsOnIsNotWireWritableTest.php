<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;
use Tests\Helpers\LivewireRoundTrip;

uses(RefreshDatabase::class);

// runId names the run confirm() and discard() act on. Both re-check ownership,
// so a foreign id 404s and nothing is disclosed — but unlocked it was still the
// client choosing which of its OWN staged runs got committed to the ledger,
// from a page headed with a different one. The Import twin, ImportResults,
// carries the lock and the same reasoning already.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'migration-run-lock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->onScreen = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'On screen.zip',
    );
    $this->neighbour = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Neighbour.zip',
    );
});

function migrationPreviewSnapshot(int $runId): string
{
    return LivewireRoundTrip::snapshotFor(
        (string) test()->get('/migrations/'.$runId.'/preview')->assertOk()->getContent(),
        'migration.preview-migration',
    );
}

function migrationRunStatus(int $runId): string
{
    /** @var MigrationRun $run */
    $run = MigrationRun::query()->findOrFail($runId);

    return (string) $run->status;
}

it('refuses a payload that moves the confirm onto a second run', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        migrationPreviewSnapshot($this->onScreen->id),
        ['runId' => $this->neighbour->id],
        [['path' => '', 'method' => 'confirm', 'params' => []]],
    )->assertForbidden();

    expect(migrationRunStatus($this->neighbour->id))->not->toBe(MigrationRunStatus::Confirmed->value)
        ->and(migrationRunStatus($this->onScreen->id))->not->toBe(MigrationRunStatus::Confirmed->value);
});

it('refuses a payload that moves the discard onto a second run', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        migrationPreviewSnapshot($this->onScreen->id),
        ['runId' => $this->neighbour->id],
        [['path' => '', 'method' => 'discard', 'params' => []]],
    )->assertForbidden();

    expect(migrationRunStatus($this->neighbour->id))->not->toBe(MigrationRunStatus::Discarded->value);
});

it('still confirms the run the preview page was opened for', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        migrationPreviewSnapshot($this->onScreen->id),
        [],
        [['path' => '', 'method' => 'confirm', 'params' => []]],
    )->assertOk();

    expect(migrationRunStatus($this->onScreen->id))->toBe(MigrationRunStatus::Confirmed->value)
        ->and(migrationRunStatus($this->neighbour->id))->not->toBe(MigrationRunStatus::Confirmed->value);
});

it('refuses a payload that moves the results page onto a second run', function (): void {
    app(ConfirmMigration::class)->__invoke($this->onScreen->id, $this->user);

    $snapshot = LivewireRoundTrip::snapshotFor(
        (string) $this->get('/migrations/'.$this->onScreen->id.'/results')->assertOk()->getContent(),
        'migration.migration-results',
    );

    LivewireRoundTrip::tamper($this, $snapshot, ['runId' => $this->neighbour->id])->assertForbidden();
});
