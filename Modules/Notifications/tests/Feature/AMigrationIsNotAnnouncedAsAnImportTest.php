<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Navigation\Destination;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;
use Modules\Notifications\Internal\Support\DeepLinkResolver;
use Modules\Notifications\Public\Dto\NotificationDto;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\NotificationQuery;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

// Driven through the promote pipeline rather than a hand-built event: the row
// the phone showed was written by whatever the migration hands the ledger, and
// a fixture dispatching TransactionBatchImported itself would decide the very
// payload under test.

uses(RefreshDatabase::class);

function mnaiUser(): User
{
    return User::query()->create([
        'username' => 'mnai-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  callable(): void  $act
 */
function mnaiUndelivered(callable $act): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery($act);
}

function mnaiMigrate(User $user, int $transactions): void
{
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $connection = $manager->connection();

    $runId = (int) $connection->table('migration_runs')->insertGetId([
        'user_id' => $user->id,
        'source_product' => 'nynab',
        'status' => 'parsed',
        'original_filename' => 'nynab-export.zip',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $connection->table('migration_staging_accounts')->insert([
        'user_id' => $user->id,
        'migration_run_id' => $runId,
        'source_external_id' => 'acct-1',
        'name' => 'nYNAB Checking',
        'kind' => 'bank',
        'currency' => 'EUR',
        'resolution_status' => 'unmapped',
    ]);

    foreach (range(1, $transactions) as $index) {
        $connection->table('migration_staging_transactions')->insert([
            'user_id' => $user->id,
            'migration_run_id' => $runId,
            'source_external_id' => 'tx-'.$index,
            'account_source_external_id' => 'acct-1',
            'posted_at' => '2026-03-0'.$index.' 00:00:00',
            'amount_minor' => -1000 - $index,
            'currency' => 'EUR',
            'settled_amount_minor' => -1000 - $index,
            'settled_currency' => 'EUR',
            'description' => 'Migrated row '.$index,
            'cleared_status' => 'cleared',
            'is_split_parent' => false,
        ]);
    }

    mnaiUndelivered(function () use ($runId, $user): void {
        app(PromoteStagingToDomain::class)->promote($runId, $user);
    });
}

function mnaiRow(User $user): NotificationDto
{
    /** @var NotificationQuery $query */
    $query = app(NotificationQuery::class);

    /** @var DeepLinkResolver $deepLinks */
    $deepLinks = app(DeepLinkResolver::class);

    $rows = $query->unreadForUser($user)['rows'];
    expect($rows)->toHaveCount(1);

    return $deepLinks->resolve($rows[0], $user);
}

it('does not tell a reader who moved their whole budget that a file was imported', function (): void {
    $user = mnaiUser();

    mnaiMigrate($user, 2);

    $row = mnaiRow($user);

    expect($row->triggerType)->not->toBe(NotificationTrigger::ImportFinished->value)
        ->and($row->title)->not->toBe('Import finished')
        ->and($row->typeWord)->not->toBe('Import');
});

it('names the migration, and counts the transactions as part of it', function (): void {
    $user = mnaiUser();

    mnaiMigrate($user, 2);

    $row = mnaiRow($user);

    expect($row->triggerType)->toBe(NotificationTrigger::MigrationFinished->value)
        ->and($row->title)->toBe('Migration finished')
        ->and($row->body)->toBe('Your budget moved over, including 2 transactions.')
        ->and($row->typeWord)->toBe('Migration');
});

it('sends the reader to their migrations, not to the blank upload form', function (): void {
    $user = mnaiUser();

    mnaiMigrate($user, 2);

    $row = mnaiRow($user);

    expect($row->deepLinkDisabled)->toBeFalse()
        ->and($row->deepLinkUrl)->not->toBe(Destination::Imports->url())
        ->and($row->deepLinkUrl)->toBe(route('migrations.index'));
});

it('still calls a parsed statement batch an import', function (): void {
    $user = mnaiUser();

    mnaiUndelivered(function () use ($user): void {
        /** @var Dispatcher $events */
        $events = app(Dispatcher::class);

        $events->dispatch(new TransactionBatchImported(
            userId: $user->id,
            insertedCount: 4,
            sourceFormats: ['camt053'],
        ));
    });

    $row = mnaiRow($user);

    expect($row->triggerType)->toBe(NotificationTrigger::ImportFinished->value)
        ->and($row->deepLinkUrl)->toBe(Destination::Imports->url());
});
