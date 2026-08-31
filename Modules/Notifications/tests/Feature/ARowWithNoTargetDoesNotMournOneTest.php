<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Support\DeepLinkResolver;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\NotificationQuery;

uses(RefreshDatabase::class);

// resolveTarget() answered "disabled" for a row that never carried a target at
// all, and resolve() then labelled it 'item', so the reader was told an item
// no longer exists about something that never did.

function noTargetUser(): User
{
    return User::query()->create([
        'username' => 'no-target-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function noTargetNotification(DatabaseManager $db, int $userId, string $params): string
{
    $id = hash('sha256', 'no-target-'.$userId.'-'.$params);

    $db->connection()->table('notifications')->insert([
        'id' => $id,
        'user_id' => $userId,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Your weekly position',
        'body' => 'Nothing needs a decision this week.',
        'params' => $params,
        'trigger_type' => NotificationTrigger::PositionDigest,
        'created_at' => '2026-07-18 09:00:00',
        'updated_at' => '2026-07-18 09:00:00',
    ]);

    return $id;
}

it('names no kind for a row that never carried a target', function (): void {
    $user = noTargetUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    noTargetNotification($db, $user->id, json_encode(['amount_minor' => 1200], JSON_THROW_ON_ERROR));

    /** @var NotificationQuery $query */
    $query = $this->app->make(NotificationQuery::class);

    /** @var DeepLinkResolver $resolver */
    $resolver = $this->app->make(DeepLinkResolver::class);

    $resolved = $resolver->resolve($query->allForUser($user)['rows'][0], $user);

    expect($resolved->targetKind)->toBeNull()
        ->and($resolved->deepLinkUrl)->toBeNull();
});

it('keeps the mourning line off the screen for a row with nothing behind it', function (): void {
    $user = noTargetUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    noTargetNotification($db, $user->id, json_encode(['amount_minor' => 1200], JSON_THROW_ON_ERROR));

    $this->actingAs($user)->get('/notifications')
        ->assertOk()
        ->assertDontSee('no longer exists');
});

it('still tells the reader when a target it did carry is gone', function (): void {
    $user = noTargetUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    noTargetNotification($db, $user->id, json_encode([
        'target_kind' => 'series',
        'target_id' => 987654,
    ], JSON_THROW_ON_ERROR));

    /** @var NotificationQuery $query */
    $query = $this->app->make(NotificationQuery::class);

    /** @var DeepLinkResolver $resolver */
    $resolver = $this->app->make(DeepLinkResolver::class);

    $resolved = $resolver->resolve($query->allForUser($user)['rows'][0], $user);

    expect($resolved->targetKind)->toBe('series')
        ->and($resolved->deepLinkDisabled)->toBeTrue();

    $this->actingAs($user)->get('/notifications')
        ->assertOk()
        ->assertSee('This series no longer exists.');
});

it('falls back to the neutral word for a kind this build cannot name', function (): void {
    $user = noTargetUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    noTargetNotification($db, $user->id, json_encode([
        'target_kind' => 'sprocket',
        'target_id' => 12,
    ], JSON_THROW_ON_ERROR));

    /** @var NotificationQuery $query */
    $query = $this->app->make(NotificationQuery::class);

    /** @var DeepLinkResolver $resolver */
    $resolver = $this->app->make(DeepLinkResolver::class);

    $resolved = $resolver->resolve($query->allForUser($user)['rows'][0], $user);

    expect($resolved->targetKind)->toBe('item')
        ->and($resolved->deepLinkDisabled)->toBeTrue();
});
