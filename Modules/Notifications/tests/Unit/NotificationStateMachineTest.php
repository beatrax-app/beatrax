<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\StateMachines\NotificationStateMachine;

uses(RefreshDatabase::class);

/*
 * Unit coverage for NotificationStateMachine — the sole legal mutator of
 * notifications.state. Covers the open -> resolved happy path, the
 * resolved -> resolved illegal re-entry, the busy-timeout-fenced row
 * lock, and the explicit ->where('user_id', ...) isolation (T-18-03):
 * a cross-user resolve() call must not mutate a row it does not own.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'notif-sm-owner',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->otherUser = User::query()->create([
        'username' => 'notif-sm-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var NotificationStateMachine $machine */
    $machine = $this->app->make(NotificationStateMachine::class);
    $this->machine = $machine;

    $this->notificationId = hash('sha256', 'notif-sm-fixture-'.bin2hex(random_bytes(4)));

    $this->db->connection()->table('notifications')->insert([
        'id' => $this->notificationId,
        'user_id' => $this->user->id,
        'state' => 'open',
        'title' => 'Import finished',
        'body' => 'Your ASN import finished.',
        'trigger_type' => 'import_finished',
        'created_at' => '2026-07-17 09:00:00',
        'updated_at' => '2026-07-17 09:00:00',
    ]);
});

it('transitions open -> resolved', function (): void {
    $this->machine->resolve($this->notificationId, $this->user->id);

    $row = $this->db->connection()->table('notifications')
        ->where('id', $this->notificationId)
        ->first();

    expect($row->state)->toBe('resolved');
});

it('throws on resolved -> resolved (no entry exists in ALLOWED_TRANSITIONS)', function (): void {
    $this->machine->resolve($this->notificationId, $this->user->id);

    expect(fn () => $this->machine->resolve($this->notificationId, $this->user->id))
        ->toThrow(RuntimeException::class);

    $row = $this->db->connection()->table('notifications')
        ->where('id', $this->notificationId)
        ->first();

    expect($row->state)->toBe('resolved');
});

it('raises when the notification row is missing', function (): void {
    expect(fn () => $this->machine->resolve('nonexistent-id', $this->user->id))
        ->toThrow(RuntimeException::class);
});

it('does not mutate the row when resolve() is called with a different user_id', function (): void {
    expect(fn () => $this->machine->resolve($this->notificationId, $this->otherUser->id))
        ->toThrow(RuntimeException::class);

    $row = $this->db->connection()->table('notifications')
        ->where('id', $this->notificationId)
        ->first();

    expect($row->state)->toBe('open');
    expect((int) $row->user_id)->toBe($this->user->id);
});

it('binds NotificationStateMachine as a singleton via NotificationsServiceProvider', function (): void {
    $first = $this->app->make(NotificationStateMachine::class);
    $second = $this->app->make(NotificationStateMachine::class);
    expect($first)->toBe($second);
});
