<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
