<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Events\BudgetThresholdCrossed;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Events\TransactionBatchImported;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Services\NotificationQuery;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

uses(RefreshDatabase::class);

function readerLanguageUser(string $username, ?string $locale): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

function readerLanguageImport(User $user, int $insertedCount): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user, $insertedCount): void {
        /** @var Dispatcher $events */
        $events = app(Dispatcher::class);

        $events->dispatch(new TransactionBatchImported(
            userId: $user->id,
            insertedCount: $insertedCount,
            sourceFormats: ['csv'],
        ));
    });
}

it('renders a notification written in English into Dutch once the reader switches language', function (): void {
    app()->setLocale('en');
    $user = readerLanguageUser('reader-language-switch', 'en');
    readerLanguageImport($user, 22);

    app()->setLocale('nl');
    /** @var NotificationQuery $query */
    $query = app(NotificationQuery::class);
    $rows = $query->allForUser($user);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->title)->toBe('Import voltooid');
    expect($rows[0]->body)->toBe('22 transacties geïmporteerd.');
});

it('picks the plural form for the reading language, not the writing one', function (): void {
    app()->setLocale('en');
    $user = readerLanguageUser('reader-language-plural', 'en');
    readerLanguageImport($user, 1);

    app()->setLocale('nl');
    /** @var NotificationQuery $query */
    $query = app(NotificationQuery::class);
    $rows = $query->allForUser($user);

    expect($rows[0]->body)->toBe('1 transactie geïmporteerd.');
});

it('keeps the stored sentence for a row written before the copy spec existed', function (): void {
    $user = readerLanguageUser('reader-language-legacy', 'en');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('notifications')->insert([
        'id' => str_repeat('e', 64),
        'user_id' => $user->id,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Import finished',
        'body' => '22 transactions imported.',
        // Exactly what the device database held: the target, and nothing
        // that could re-render the sentence.
        'params' => '{"target_kind":"import"}',
        'trigger_type' => DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        'created_at' => '2026-07-18 09:00:00',
        'updated_at' => '2026-07-18 09:00:00',
    ]);

    app()->setLocale('nl');
    /** @var NotificationQuery $query */
    $query = app(NotificationQuery::class);
    $rows = $query->allForUser($user);

    expect($rows[0]->title)->toBe('Import finished');
    expect($rows[0]->body)->toBe('22 transactions imported.');
});

it('leaves the deep-link target alongside the copy it now stores', function (): void {
    app()->setLocale('en');
    $user = readerLanguageUser('reader-language-target', 'en');
    readerLanguageImport($user, 4);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $params = $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->value('params');

    expect($params)->toBeString();
    /** @var string $params */
    $decoded = json_decode($params, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();
    /** @var array<string, mixed> $decoded */
    expect($decoded['target_kind'] ?? null)->toBe('import');
    expect($decoded['copy'] ?? null)->toBeArray();
});

// A budget nudge fires from a job, which holds the app default locale and not
// the recipient's. The amounts in it used to be formatted before the renderer
// switched language, so a Dutch reader got English separators forever.
function readerLanguageNudge(User $user): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user): void {
        /** @var Dispatcher $events */
        $events = app(Dispatcher::class);

        $events->dispatch(new BudgetThresholdCrossed(
            userId: $user->id,
            categoryId: 7,
            categoryName: 'Groceries',
            period: '2026-08',
            thresholdPercent: 80,
            spentMinor: 123456,
            budgetMinor: 150000,
            currency: 'EUR',
            categorySlug: 'groceries',
            categoryNameIsDefault: true,
        ));
    });
}

it('falls back to the stored sentence when a later release removed the copy key', function (): void {
    $user = readerLanguageUser('reader-language-dead-key', 'nl');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('notifications')->insert([
        'id' => str_repeat('d', 64),
        'user_id' => $user->id,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Import finished',
        'body' => '22 transactions imported.',
        // A spec whose keys were valid when it was written and are not any
        // more. Lang answers a miss with the key, so without a fallback the
        // reader sees the key.
        'params' => json_encode([
            'target_kind' => 'import',
            'copy' => [
                'title' => ['key' => 'notifications::copy.title.retired_key', 'replace' => [], 'count' => null],
                'body' => [['key' => 'notifications::copy.body.retired_key', 'replace' => [], 'count' => null]],
            ],
        ], JSON_THROW_ON_ERROR),
        'trigger_type' => DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        'created_at' => '2026-07-18 09:00:00',
        'updated_at' => '2026-07-18 09:00:00',
    ]);

    app()->setLocale('nl');
    /** @var NotificationQuery $query */
    $query = app(NotificationQuery::class);
    $rows = $query->allForUser($user);

    expect($rows[0]->title)->toBe('Import finished')
        ->and($rows[0]->body)->toBe('22 transactions imported.');
});

it('writes a nudge s money in the recipient s language, not the job s', function (): void {
    app()->setLocale('en');
    $user = readerLanguageUser('reader-language-money-write', 'nl');
    readerLanguageNudge($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $body = $db->connection()->table('notifications')->where('user_id', $user->id)->value('body');

    expect($body)->toBeString()
        ->and($body)->toContain('1.234,56')
        ->and($body)->not->toContain('1,234.56');
});

it('re-renders a nudge s money when the reader switches language', function (): void {
    app()->setLocale('en');
    $user = readerLanguageUser('reader-language-money-read', 'en');
    readerLanguageNudge($user);

    /** @var NotificationQuery $query */
    $query = app(NotificationQuery::class);

    app()->setLocale('en');
    $english = $query->allForUser($user)[0]->body;

    app()->setLocale('nl');
    $dutch = $query->allForUser($user)[0]->body;

    expect($english)->toContain('1,234.56')
        ->and($dutch)->toContain('1.234,56');
});

