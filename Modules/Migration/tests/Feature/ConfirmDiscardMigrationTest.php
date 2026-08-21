<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Actions\ConfirmMigration;
use Modules\Migration\Public\Actions\DiscardMigrationRun;
use Modules\Migration\Public\Actions\StartMigrationRun;
use Modules\Migration\Public\Exceptions\MigrationAlreadyConfirmedException;
use Modules\Migration\Public\Exceptions\MigrationAlreadyDiscardedException;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'confirm-discard-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

it('ConfirmMigration: re-invoking on a confirmed run performs zero additional writes and returns persisted counts', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $first = app(ConfirmMigration::class)->__invoke($run->id, $this->user);
    expect($first->categoriesCreated)->toBeGreaterThan(0);

    $transactionsCountAfterFirst = $this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count();
    $categoriesCountAfterFirst = $this->db->connection()->table('categories')->where('user_id', $this->user->id)->count();

    $second = app(ConfirmMigration::class)->__invoke($run->id, $this->user);

    expect($second->migrationRunId)->toBe($run->id);
    expect($second->categoriesCreated)->toBe($first->categoriesCreated);
    expect($second->transactionsInserted)->toBe($first->transactionsInserted);

    expect($this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count())
        ->toBe($transactionsCountAfterFirst);
    expect($this->db->connection()->table('categories')->where('user_id', $this->user->id)->count())
        ->toBe($categoriesCountAfterFirst);
});

it('ConfirmMigration: a foreign run id resolves to 404, never mutating another user\'s data', function (): void {
    $owner = User::create(['username' => 'confirm-owner', 'password' => 'opensesame', 'period_start_day' => 1]);
    $run = app(StartMigrationRun::class)->__invoke(
        $owner,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    expect(fn () => app(ConfirmMigration::class)->__invoke($run->id, $this->user))
        ->toThrow(ModelNotFoundException::class);
});

it('DiscardMigrationRun: empties this run\'s staging and leaves domain tables untouched', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    expect($this->db->connection()->table('migration_staging_categories')->where('migration_run_id', $run->id)->count())
        ->toBeGreaterThan(0);

    app(DiscardMigrationRun::class)->__invoke($run->id, $this->user);

    expect($run->refresh()->status)->toBe('discarded');
    expect($this->db->connection()->table('migration_staging_categories')->where('migration_run_id', $run->id)->count())->toBe(0);
    expect($this->db->connection()->table('migration_staging_transactions')->where('migration_run_id', $run->id)->count())->toBe(0);
    expect($this->db->connection()->table('categories')->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('DiscardMigrationRun: refuses to discard an already-confirmed run', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($run->id, $this->user);

    expect(fn () => app(DiscardMigrationRun::class)->__invoke($run->id, $this->user))
        ->toThrow(MigrationAlreadyConfirmedException::class);

    expect($run->refresh()->status)->toBe('confirmed');
});

it('ConfirmMigration: refuses to confirm an already-discarded run', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    app(DiscardMigrationRun::class)->__invoke($run->id, $this->user);

    expect(fn () => app(ConfirmMigration::class)->__invoke($run->id, $this->user))
        ->toThrow(MigrationAlreadyDiscardedException::class);

    // Never silently flipped back to 'confirmed' with phantom zero counts.
    expect($run->refresh()->status)->toBe('discarded');
    expect($this->db->connection()->table('categories')->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('DiscardMigrationRun: a foreign run id resolves to 404', function (): void {
    $owner = User::create(['username' => 'discard-owner', 'password' => 'opensesame', 'period_start_day' => 1]);
    $run = app(StartMigrationRun::class)->__invoke(
        $owner,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    expect(fn () => app(DiscardMigrationRun::class)->__invoke($run->id, $this->user))
        ->toThrow(ModelNotFoundException::class);
});

it('DiscardMigrationRun: sweepAbandonedForUser reclaims only THIS user\'s stale never-confirmed runs', function (): void {
    $otherUser = User::create(['username' => 'sweep-other-user', 'password' => 'opensesame', 'period_start_day' => 1]);

    $staleRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    MigrationRun::query()->where('id', $staleRun->id)->update(['created_at' => now()->subDays(2)]);

    $otherStaleRun = app(StartMigrationRun::class)->__invoke(
        $otherUser,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );
    MigrationRun::query()->where('id', $otherStaleRun->id)->update(['created_at' => now()->subDays(2)]);

    $freshRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $reclaimed = app(DiscardMigrationRun::class)->sweepAbandonedForUser($this->user);

    expect($reclaimed)->toBe(1);
    expect(MigrationRun::query()->where('id', $staleRun->id)->exists())->toBeFalse();
    expect(MigrationRun::query()->where('id', $freshRun->id)->exists())->toBeTrue();
    expect(MigrationRun::query()->where('id', $otherStaleRun->id)->exists())->toBeTrue();
});
