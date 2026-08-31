<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Internal\Http\Livewire\BackfillWindowModal;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;

// Non-adversarial trigger: two tabs on /inboxes, and the row this one acts on
// was disconnected in the other. The ownership read is right; letting its
// 404 out of a wire round-trip is not.

function inboxActorUser(): User
{
    return User::query()->create([
        'username' => 'inbox-gone-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function seedInbox(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $userId,
        'provider' => 'gmail',
        'email' => 'seeded@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $userId,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

it('answers every inbox action on a vanished row with a calm notice, never a 404', function (): void {
    $user = inboxActorUser();
    $this->actingAs($user);

    $calls = [
        ['editWindow', [999999]],
        ['scanNow', [999999]],
        ['reconnect', [999999]],
        ['disconnect', [999999]],
        ['promoteSender', [999999]],
        ['dismissSender', [999999]],
    ];

    foreach ($calls as [$method, $params]) {
        Livewire::test(InboxesPage::class)
            ->call($method, ...$params)
            ->assertStatus(200)
            ->assertDispatched('toast', message: Lang::get('core::errors.no_longer_here'));
    }
});

it('never claims a sender was added when the sender is already gone', function (): void {
    $this->actingAs(inboxActorUser());

    Livewire::test(InboxesPage::class)
        ->call('promoteSender', 999999)
        ->assertNotDispatched('toast', message: Lang::get('email-scan::inboxes.toast.sender_added'));
});

it('answers a backfill submit on a vanished inbox inside the modal, never a 404', function (): void {
    $user = inboxActorUser();
    $this->actingAs($user);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', inboxId: 999999)
        ->call('submit')
        ->assertStatus(200)
        ->assertSet('errorMessage', Lang::get('core::errors.no_longer_here'))
        ->assertNotDispatched('modal-close');
});

it('answers a backfill submit with no inbox at all inside the modal', function (): void {
    $this->actingAs(inboxActorUser());

    Livewire::test(BackfillWindowModal::class)
        ->call('submit')
        ->assertStatus(200)
        ->assertSet('errorMessage', Lang::get('core::errors.no_longer_here'));
});

it('still acts on an inbox that is really there', function (): void {
    Bus::fake();
    $user = inboxActorUser();
    $this->actingAs($user);
    $inboxId = seedInbox($user->id);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', inboxId: $inboxId)
        ->call('submit')
        ->assertStatus(200)
        ->assertSet('errorMessage', '')
        ->assertDispatched('modal-close');
});
