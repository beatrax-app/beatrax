<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Notifications\Public\Enums\NotificationTrigger;

uses(RefreshDatabase::class);

function dldUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dldInsertNotification(DatabaseManager $db, int $userId, string $id, array $overrides = []): void
{
    $db->connection()->table('notifications')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Payment due Friday',
        'body' => 'Netflix — 12.99 EUR.',
        'params' => json_encode(['target_kind' => 'dashboard'], JSON_THROW_ON_ERROR),
        'trigger_type' => NotificationTrigger::PaymentReminder,
        'created_at' => '2026-07-18 09:00:00',
        'updated_at' => '2026-07-18 09:00:00',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dldSeries(DatabaseManager $db, int $userId, array $overrides = []): int
{
    return $db->connection()->table('recurring_series')->insertGetId(array_merge([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Netflix',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1299,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'dld::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ], $overrides));
}

function dldCategory(DatabaseManager $db, ?int $userId): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Groceries',
        'slug' => 'dld-groceries-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'display_order' => 100,
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);
}

function dldCounterparty(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'dld-netflix-'.bin2hex(random_bytes(4)),
        'display_name' => 'Netflix',
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('renders a deleted series target as a disabled, explained link with no error and no 404', function (): void {
    $user = dldUser('dld-series-deleted');
    $seriesId = dldSeries($this->db, $user->id);
    $id = str_repeat('1', 64);
    dldInsertNotification($this->db, $user->id, $id, [
        'params' => json_encode(['target_kind' => 'series', 'target_id' => $seriesId], JSON_THROW_ON_ERROR),
    ]);

    $this->db->connection()->table('recurring_series')->where('id', $seriesId)->delete();

    $response = $this->actingAs($user)->get('/notifications?tab=all');

    $response->assertOk()
        ->assertSeeText('Payment due Friday')
        ->assertSeeText('This series no longer exists.')
        ->assertSee('aria-disabled="true"', false);
});

it('renders a deleted budget (category) target as a disabled, explained link', function (): void {
    $user = dldUser('dld-budget-deleted');
    $categoryId = dldCategory($this->db, $user->id);
    $id = str_repeat('2', 64);
    dldInsertNotification($this->db, $user->id, $id, [
        'title' => 'Budget nearly spent',
        'body' => 'Groceries is at 92%.',
        'trigger_type' => NotificationTrigger::BudgetNudge,
        'params' => json_encode(['target_kind' => 'budget', 'target_id' => $categoryId], JSON_THROW_ON_ERROR),
    ]);

    $this->db->connection()->table('categories')->where('id', $categoryId)->delete();

    $response = $this->actingAs($user)->get('/notifications?tab=all');

    $response->assertOk()
        ->assertSeeText('Budget nearly spent')
        ->assertSeeText('This budget no longer exists.')
        ->assertSee('aria-disabled="true"', false);
});

it('renders a deleted counterparty target as a disabled, explained link', function (): void {
    $user = dldUser('dld-counterparty-deleted');
    $counterpartyId = dldCounterparty($this->db, $user->id);
    $id = str_repeat('3', 64);
    dldInsertNotification($this->db, $user->id, $id, [
        'title' => 'A cheaper plan exists',
        'body' => 'Netflix may have a cheaper plan.',
        'trigger_type' => NotificationTrigger::SavingsPrompt,
        'params' => json_encode(['target_kind' => 'counterparty', 'target_id' => $counterpartyId], JSON_THROW_ON_ERROR),
    ]);

    $this->db->connection()->table('counterparties')->where('id', $counterpartyId)->delete();

    $response = $this->actingAs($user)->get('/notifications?tab=all');

    $response->assertOk()
        ->assertSeeText('A cheaper plan exists')
        ->assertSeeText('This counterparty no longer exists.')
        ->assertSee('aria-disabled="true"', false);
});

it('renders a live target as a clickable, non-disabled link (negative control)', function (): void {
    $user = dldUser('dld-series-live');
    $seriesId = dldSeries($this->db, $user->id);
    $id = str_repeat('4', 64);
    dldInsertNotification($this->db, $user->id, $id, [
        'params' => json_encode(['target_kind' => 'series', 'target_id' => $seriesId], JSON_THROW_ON_ERROR),
    ]);

    $response = $this->actingAs($user)->get('/notifications?tab=all');

    $response->assertOk()
        ->assertSeeText('Payment due Friday')
        ->assertDontSeeText('no longer exists')
        ->assertSee('/recurring/series/'.$seriesId, false);

    expect($response->getContent() ?: '')->not->toContain('aria-disabled="true"');
});

it('never disables a dashboard-target digest', function (): void {
    $user = dldUser('dld-dashboard-digest');
    $id = str_repeat('5', 64);
    dldInsertNotification($this->db, $user->id, $id, [
        'title' => 'Your weekly position',
        'body' => 'In 1,000.00 EUR, out 800.00 EUR, net 200.00 EUR.',
        'trigger_type' => NotificationTrigger::PositionDigest,
        'params' => json_encode(['target_kind' => 'dashboard'], JSON_THROW_ON_ERROR),
    ]);

    $response = $this->actingAs($user)->get('/notifications?tab=all');

    $response->assertOk()
        ->assertSeeText('Your weekly position')
        ->assertDontSeeText('no longer exists');

    expect($response->getContent() ?: '')->not->toContain('aria-disabled="true"');
});

it('degrades a cross-user target with the SAME generic copy as a genuinely deleted target (no information disclosure)', function (): void {
    $owner = dldUser('dld-cross-user-owner');
    $attacker = dldUser('dld-cross-user-attacker');

    // Alive, and owned by someone else.
    $foreignSeriesId = dldSeries($this->db, $owner->id);

    $attackerNotificationId = str_repeat('6', 64);
    dldInsertNotification($this->db, $attacker->id, $attackerNotificationId, [
        'params' => json_encode(['target_kind' => 'series', 'target_id' => $foreignSeriesId], JSON_THROW_ON_ERROR),
    ]);

    $crossUserResponse = $this->actingAs($attacker)->get('/notifications?tab=all');
    $crossUserResponse->assertOk()->assertSeeText('This series no longer exists.');

    // The comparison baseline: genuinely deleted, and the attacker's own.
    $deletedSeriesId = dldSeries($this->db, $attacker->id);
    $deletedNotificationId = str_repeat('7', 64);
    dldInsertNotification($this->db, $attacker->id, $deletedNotificationId, [
        'params' => json_encode(['target_kind' => 'series', 'target_id' => $deletedSeriesId], JSON_THROW_ON_ERROR),
    ]);
    $this->db->connection()->table('recurring_series')->where('id', $deletedSeriesId)->delete();

    $deletedResponse = $this->actingAs($attacker)->get('/notifications?tab=all');
    $deletedResponse->assertOk()->assertSeeText('This series no longer exists.');

    // A live href on the cross-user row would confirm the series exists.
    expect($crossUserResponse->getContent() ?: '')->not->toContain('/recurring/series/'.$foreignSeriesId);
});

// Unreadable params name no target, and a row that names no target is a
// plain non-link: claiming an item is gone is a claim this row cannot make
// and the reader cannot check.
it('degrades a notification whose params JSON is malformed to a disabled link that claims nothing', function (): void {
    $user = dldUser('dld-malformed-params');
    $id = str_repeat('8', 64);
    dldInsertNotification($this->db, $user->id, $id, [
        'params' => 'this-is-not-valid-json',
    ]);

    $response = $this->actingAs($user)->get('/notifications?tab=all');

    $response->assertOk()
        ->assertSeeText('Payment due Friday')
        ->assertDontSeeText('no longer exists')
        ->assertSee('aria-disabled="true"', false);
});

it('degrades a notification whose params column is an empty string to a disabled link that claims nothing', function (): void {
    $user = dldUser('dld-empty-params');
    $id = str_repeat('9', 64);
    dldInsertNotification($this->db, $user->id, $id, [
        'params' => '',
    ]);

    $response = $this->actingAs($user)->get('/notifications?tab=all');

    $response->assertOk()
        ->assertSeeText('Payment due Friday')
        ->assertDontSeeText('no longer exists')
        ->assertSee('aria-disabled="true"', false);
});
