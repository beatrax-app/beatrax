<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;

function snatUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function snatSeedInbox(User $owner, string $status = 'idle', string $provider = 'gmail'): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => $provider,
        'email' => $owner->username.'@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => $status,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

it('scanNow dispatches IncrementalScanJob via Bus + emits a toast', function (): void {
    Bus::fake();
    $user = snatUser('happy@example.com');
    $inboxId = snatSeedInbox($user, status: 'idle');
    $this->actingAs($user);

    Livewire::test(InboxesPage::class)
        ->call('scanNow', $inboxId)
        ->assertDispatched('toast', message: 'Scan started.');

    Bus::assertDispatched(
        IncrementalScanJob::class,
        fn (IncrementalScanJob $j) => $j->inboxId === $inboxId
    );
});

it('scanNow on a backfilling inbox emits the in-progress toast and does NOT dispatch', function (): void {
    Bus::fake();
    $user = snatUser('backfilling@example.com');
    $inboxId = snatSeedInbox($user, status: 'backfilling');
    $this->actingAs($user);

    Livewire::test(InboxesPage::class)
        ->call('scanNow', $inboxId)
        ->assertDispatched('toast', message: 'Scan already in progress.');

    Bus::assertNotDispatched(IncrementalScanJob::class);
});

it('scanNow on a scanning inbox emits the in-progress toast and does NOT dispatch', function (): void {
    Bus::fake();
    $user = snatUser('scanning@example.com');
    $inboxId = snatSeedInbox($user, status: 'scanning');
    $this->actingAs($user);

    Livewire::test(InboxesPage::class)
        ->call('scanNow', $inboxId)
        ->assertDispatched('toast', message: 'Scan already in progress.');

    Bus::assertNotDispatched(IncrementalScanJob::class);
});

// A needs_reauth inbox exits IncrementalScanJob on its first status read, so
// dispatching there is a no-op the user was told had started a scan.
it('scanNow on a needs_reauth inbox tells the user to reconnect and does NOT dispatch', function (): void {
    Bus::fake();
    $user = snatUser('reauth@example.com');
    $inboxId = snatSeedInbox($user, status: 'needs_reauth');
    $this->actingAs($user);

    Livewire::test(InboxesPage::class)
        ->call('scanNow', $inboxId)
        ->assertDispatched('toast', message: Lang::get('email-scan::inboxes.toast.reconnect_first'));

    Bus::assertNotDispatched(IncrementalScanJob::class);
});

it('scanNow cross-user refusal: another user\'s inbox queues nothing and answers with a toast', function (): void {
    Bus::fake();
    $userA = snatUser('a@example.com');
    $userB = snatUser('b@example.com');
    $inboxA = snatSeedInbox($userA);

    $this->actingAs($userB);

    Livewire::test(InboxesPage::class)
        ->call('scanNow', $inboxA)
        ->assertStatus(200)
        ->assertDispatched('toast', message: Lang::get('core::errors.no_longer_here'));

    Bus::assertNotDispatched(IncrementalScanJob::class);
});

it('reconnect redirects to /oauth/connect/{provider}?inbox_id={id}', function (): void {
    $user = snatUser('reconn@example.com');
    $inboxId = snatSeedInbox($user, status: 'needs_reauth', provider: 'microsoft');
    $this->actingAs($user);

    Livewire::test(InboxesPage::class)
        ->call('reconnect', $inboxId)
        ->assertRedirect("/oauth/connect/microsoft?inbox_id={$inboxId}");
});

it('reconnect cross-user refusal: another user\'s inbox does not redirect and answers with a toast', function (): void {
    $userA = snatUser('cross-a@example.com');
    $userB = snatUser('cross-b@example.com');
    $inboxA = snatSeedInbox($userA, status: 'needs_reauth');

    $this->actingAs($userB);

    Livewire::test(InboxesPage::class)
        ->call('reconnect', $inboxA)
        ->assertStatus(200)
        ->assertNoRedirect()
        ->assertDispatched('toast', message: Lang::get('core::errors.no_longer_here'));
});
