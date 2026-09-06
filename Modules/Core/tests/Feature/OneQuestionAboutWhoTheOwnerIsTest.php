<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\OwnerAccount;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

uses(RefreshDatabase::class);

// Settings that belong to the installation rather than to a reader live on the
// oldest account. Three services had each worked that out for themselves, with
// three different ideas about a table that is not there yet, and a fourth was
// about to be written for the rebuild command.

function ownerAccountLogger(array &$recorded): LoggerInterface
{
    return new class($recorded) implements LoggerInterface
    {
        use LoggerTrait;

        /** @param array<int, array{level: string, message: string, context: array<string, mixed>}> $recorded */
        public function __construct(public array &$recorded) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->recorded[] = [
                'level' => is_string($level) ? $level : (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    };
}

it('answers with the oldest account, not whoever is signed in', function (): void {
    $first = User::query()->create([
        'username' => 'owner-first',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    User::query()->create([
        'username' => 'owner-second',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $recorded = [];
    $owner = new OwnerAccount(app(DatabaseManager::class), ownerAccountLogger($recorded));

    expect($owner->id())->toBe($first->id)
        ->and($owner->column('username'))->toBe('owner-first')
        ->and($recorded)->toBe([], 'a healthy read logged something');
});

it('reports no owner on an installation that has none yet', function (): void {
    $recorded = [];
    $owner = new OwnerAccount(app(DatabaseManager::class), ownerAccountLogger($recorded));

    expect($owner->id())->toBeNull()
        ->and($recorded)->toBe([], 'an empty table is not a failure and must not be logged as one');
});

// The branch every caller had written for itself: a read that reaches the table
// before it exists. It answers "no owner" — the same answer as an empty table,
// which is why no call site needs a second branch — and says so once, because a
// column that has genuinely gone missing must not read as a first install.
it('answers no owner when the table cannot be read, and says so', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->statement('drop table users');

    $recorded = [];
    $owner = new OwnerAccount($db, ownerAccountLogger($recorded));

    expect($owner->id())->toBeNull();

    $warnings = array_values(array_filter(
        $recorded,
        static fn (array $line): bool => $line['level'] === 'warning'
            && str_contains($line['message'], 'could not read the owner row'),
    ));

    expect($warnings)->toHaveCount(1, 'an unreadable owner row went by in silence')
        ->and($warnings[0]['context'])->toHaveKey('column')
        ->and($warnings[0]['context']['column'])->toBe('id');
});

it('stays quiet when it has no logger to speak through', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->statement('drop table users');

    expect((new OwnerAccount($db))->id())->toBeNull();
});
