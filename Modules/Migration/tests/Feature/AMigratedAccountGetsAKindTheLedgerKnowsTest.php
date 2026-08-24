<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Migration\Internal\Dto\MigrationAccountDto;
use Modules\Migration\Internal\Dto\MigrationBatch;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;
use Modules\Migration\Internal\Pipeline\StagingWriter;

uses(RefreshDatabase::class);

// The staged kind is copied verbatim into accounts.kind, so the staging
// default has to be a word the Ledger vocabulary contains. Driving the real
// StagingWriter is the point: the promote fixtures all hand-seed 'bank', so
// a wrong default is invisible to them.

function amaUser(): User
{
    return User::create([
        'username' => 'migrated-account-kind',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/**
 * @return array{0: User, 1: int}
 */
function amaStage(): array
{
    $user = amaUser();

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);

    $runId = (int) $manager->connection()->table('migration_runs')->insertGetId([
        'user_id' => $user->id,
        'source_product' => 'ynab4',
        'status' => 'parsed',
        'original_filename' => 'fixture.zip',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Exactly what both parsers build: no kind argument at all.
    $batch = new MigrationBatch(
        sourceProduct: 'ynab4',
        budgetCurrency: 'EUR',
        categories: new Collection,
        accounts: new Collection([new MigrationAccountDto(
            sourceExternalId: 'acct-1',
            name: 'Migrated Current',
            currency: 'EUR',
        )]),
        payees: new Collection,
        budgetAssignments: new Collection,
        goals: new Collection,
        schedules: new Collection,
        unmapped: new Collection,
        transactions: [],
    );

    app(StagingWriter::class)->write($batch, $runId, $user);

    return [$user, $runId];
}

it('stages an account under a kind the Ledger vocabulary contains', function (): void {
    [$user, $runId] = amaStage();

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $staged = (string) $manager->connection()->table('migration_staging_accounts')
        ->where('migration_run_id', $runId)
        ->value('kind');

    expect(AccountKind::tryFrom($staged))->not->toBeNull(
        'migration_staging_accounts.kind was "'.$staged.'", which is not an AccountKind.',
    );
});

it('promotes it into an account every accounts.kind consumer can see', function (): void {
    [$user, $runId] = amaStage();

    app(PromoteStagingToDomain::class)->promote($runId, $user);

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $kind = (string) $manager->connection()->table('accounts')
        ->where('user_id', $user->id)
        ->value('kind');

    expect(AccountKind::tryFrom($kind))->not->toBeNull(
        'accounts.kind was "'.$kind.'", which no consumer of that column matches.',
    );
});
