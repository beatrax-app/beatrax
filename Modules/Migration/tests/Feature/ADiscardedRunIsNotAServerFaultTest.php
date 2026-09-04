<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Migration\Internal\Enums\MigrationRunStatus;

function discardedRunReader(): User
{
    return User::query()->create([
        'username' => 'discarded-run-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function discardedRunFor(User $user, MigrationRunStatus $status): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('migration_runs')->insertGetId([
        'user_id' => $user->id,
        'source_product' => 'ynab4',
        'status' => $status->value,
        'original_filename' => 'Budget.zip',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// Discarding truncates staging and leaves the run row behind, so the back
// button lands on a preview with nothing to preview. It answered 500: the same
// screen said 404 for a run that never existed and "server fault" for one the
// reader themselves threw away a moment earlier.
it('tells the reader the run was discarded rather than answering a server fault', function (): void {
    $user = discardedRunReader();
    $runId = discardedRunFor($user, MigrationRunStatus::Discarded);

    $this->actingAs($user)
        ->get('/migrations/'.$runId.'/preview')
        ->assertOk()
        ->assertSee(Lang::get('migration::preview.discarded'))
        ->assertSee(Lang::get('migration::preview.discarded_link'));
});

// The neighbouring answer, pinned so the two stay different: a run this reader
// does not have is gone, and gone is a 404.
it('keeps 404 for a run id that names nothing', function (): void {
    $user = discardedRunReader();

    $this->actingAs($user)
        ->get('/migrations/424242/preview')
        ->assertNotFound();
});

it('still shows the counts for a run that was not discarded', function (): void {
    $user = discardedRunReader();
    $runId = discardedRunFor($user, MigrationRunStatus::Parsed);

    $this->actingAs($user)
        ->get('/migrations/'.$runId.'/preview')
        ->assertOk()
        ->assertSee(Lang::get('migration::preview.stats.transaction'))
        ->assertDontSee(Lang::get('migration::preview.discarded'));
});
