<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\NotificationCopy;
use Modules\Notifications\Public\Services\NotificationQuery;

uses(RefreshDatabase::class);

// The state a desktop reached on its own: rows seeded before encryption, sync
// enabled afterwards so the enable-time sweep encrypts them, and then a reader
// whose keyring no longer opens. SensitiveColumnCodec answers that with '' —
// its documented shape for a sealed value — which is a lookup key here.
const SEALED_KEY = '11111111111111111111111111111111';

const SEALED_OTHER_KEY = '22222222222222222222222222222222';

function sealedUser(): User
{
    return User::query()->create([
        'username' => 'sealed-inbox-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sealedInsertNotification(DatabaseManager $db, int $userId, string $id, array $overrides = []): void
{
    $db->connection()->table('notifications')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Budget nearly spent',
        'body' => 'Groceries is at 92% of its budget.',
        'params' => json_encode(['target_kind' => 'dashboard'], JSON_THROW_ON_ERROR),
        'trigger_type' => NotificationTrigger::BudgetNudge,
        'created_at' => '2026-08-15 09:00:00',
        'updated_at' => '2026-08-15 09:00:00',
    ], $overrides));
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $this->session = $session;

    $this->user = sealedUser();
    sealedInsertNotification($this->db, $this->user->id, str_repeat('a', 64));

    AppLockTestHarness::unlock($this->session, SEALED_KEY);

    /** @var EncryptionMigrationService $migration */
    $migration = $this->app->make(EncryptionMigrationService::class);
    $migration->migrate($this->user, $this->session);
});

// Without this the rest is vacuous: decrypting a value that was never encrypted
// is a documented no-op, so a fixture that failed to sweep would let a broken
// reader pass every assertion below.
it('leaves the swept trigger_type as ciphertext at rest', function (): void {
    $stored = $this->db->connection()->table('notifications')
        ->where('id', str_repeat('a', 64))
        ->value('trigger_type');

    expect($stored)->toBeString()
        ->not->toBe(NotificationTrigger::BudgetNudge)
        ->and(strlen((string) $stored))->toBeGreaterThan(40);
});

it('reads the swept row back under the key that sealed it', function (): void {
    /** @var NotificationQuery $query */
    $query = $this->app->make(NotificationQuery::class);

    $rows = $query->allForUser($this->user)['rows'];

    expect($rows)->toHaveCount(1);
    expect($rows[0]->triggerType)->toBe(NotificationTrigger::BudgetNudge->value);
    expect($rows[0]->unreadable)->toBeFalse();
    expect($rows[0]->title)->toBe('Budget nearly spent');
});

it('returns a row rather than throwing when the keyring no longer opens', function (): void {
    AppLockTestHarness::unlock($this->session, SEALED_OTHER_KEY);

    /** @var NotificationQuery $query */
    $query = $this->app->make(NotificationQuery::class);

    $rows = $query->allForUser($this->user)['rows'];

    expect($rows)->toHaveCount(1);
    expect($rows[0]->triggerType)->toBe('');
    expect($rows[0]->unreadable)->toBeTrue();
    expect($rows[0]->glyph)->toBe(NotificationCopy::typeChip('')['glyph']);
});

it('returns a row rather than throwing when the app lock withholds the key', function (): void {
    AppLockTestHarness::lock($this->session);

    /** @var NotificationQuery $query */
    $query = $this->app->make(NotificationQuery::class);

    $rows = $query->allForUser($this->user)['rows'];

    expect($rows)->toHaveCount(1);
    expect($rows[0]->unreadable)->toBeTrue();
});

it('renders /notifications and says the row is sealed rather than gone', function (): void {
    AppLockTestHarness::unlock($this->session, SEALED_OTHER_KEY);

    $response = $this->actingAs($this->user)->get('/notifications?tab=all');

    $response->assertOk();
    $response->assertSee('This notification is encrypted and could not be opened on this device.');
    $response->assertDontSee('no longer exists');
});

it('names an unrecognised trigger type with the neutral chip instead of throwing', function (): void {
    expect(NotificationCopy::typeChip('a_kind_a_later_release_writes'))
        ->toBe(NotificationCopy::typeChip(''));
    expect(NotificationCopy::names('a_kind_a_later_release_writes'))->toBeFalse();
    expect(NotificationCopy::names(NotificationTrigger::BudgetNudge->value))->toBeTrue();
});
