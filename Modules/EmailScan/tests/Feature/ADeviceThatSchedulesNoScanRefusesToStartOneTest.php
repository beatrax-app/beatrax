<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;

// The screen already told the reader this device does not scan, and then
// offered Scan now anyway. Pressing it on a phone dispatched the job, moved
// last_scan_at on the way to failing — overwriting the desktop's real "3h ago"
// with "22s ago" — and left the row in Error advising a reconnect that cannot
// help here. Measured on an iPhone before this gate existed.
//
// Named apart from the fixtures in ScanNowActionTest: both files load into one
// process and a second global of the same name is a fatal.
function noScanUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function noScanInbox(User $owner): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
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
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

it('dispatches nothing when the device schedules no scan', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');
    Bus::fake();

    $user = noScanUser('phone-refuses-scan');
    $inboxId = noScanInbox($user);
    $this->actingAs($user);

    Livewire::test(InboxesPage::class)->call('scanNow', $inboxId);

    Bus::assertNotDispatched(IncrementalScanJob::class);
});

it('still dispatches where the scan does run', function (): void {
    putenv('NATIVEPHP_PLATFORM');
    Bus::fake();

    $user = noScanUser('desktop-runs-scan');
    $inboxId = noScanInbox($user);
    $this->actingAs($user);

    Livewire::test(InboxesPage::class)->call('scanNow', $inboxId);

    Bus::assertDispatched(IncrementalScanJob::class);
});

it('does not offer the control it would refuse', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    $user = noScanUser('phone-hides-scan');
    noScanInbox($user);
    $this->actingAs($user);

    $html = Livewire::test(InboxesPage::class)->html();

    // The button is still drawn, disabled, rather than removed: its absence
    // would read as a missing feature where the disabled control plus its
    // title says which device does the work.
    expect($html)->toContain('cursor-not-allowed');
});
