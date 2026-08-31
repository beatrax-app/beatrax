<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Public\Dto\EmailScanHealthTile;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

// All five email-scan schedule entries are Schedule::call() closures that
// SchedulerManifestGenerator drops, so on a phone last_scan_at stays null for
// as long as the mailbox is connected. An amber "stale" dot there names a
// schedule that fell behind on a device where none was ever set.

function htpsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function htpsSeedInbox(User $owner, string $status = 'idle', ?string $lastScanAt = null, string $email = 'reader@example.com'): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
        'email' => $email,
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
        'last_scan_at' => $lastScanAt,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

function htpsTile(User $owner): EmailScanHealthTile
{
    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = app(ThisPeriodAtAGlanceQuery::class);
    $tile = $glance->emailScanHealth($owner);

    expect($tile)->toBeInstanceOf(EmailScanHealthTile::class);

    /** @var EmailScanHealthTile $tile */
    return $tile;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-17 12:00:00'));
    putenv('NATIVEPHP_PLATFORM');
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    putenv('NATIVEPHP_PLATFORM');
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
});

it('does not call a phone inbox stale for never having been scanned where nothing scans', function (): void {
    $reader = htpsUser('phone-never');
    htpsSeedInbox($reader);

    putenv('NATIVEPHP_PLATFORM=ios');
    $tile = htpsTile($reader);

    expect($tile->lines[0]->status)->toBe('unscheduled')
        ->and($tile->overallStatus)->toBe('unscheduled');
});

it('keeps calling a never-scanned inbox stale on the desktop, where a schedule really runs', function (): void {
    $reader = htpsUser('desktop-never');
    htpsSeedInbox($reader);

    $tile = htpsTile($reader);

    expect($tile->lines[0]->status)->toBe('stale')
        ->and($tile->overallStatus)->toBe('stale');
});

it('keeps the genuine desktop stale case, where the last scan is older than the threshold', function (): void {
    $reader = htpsUser('desktop-old');
    htpsSeedInbox($reader, lastScanAt: CarbonImmutable::now()->subHours(30)->toDateTimeString());

    $tile = htpsTile($reader);

    expect($tile->lines[0]->status)->toBe('stale')
        ->and($tile->overallStatus)->toBe('stale');
});

it('does not call a phone inbox stale for an old scan either, since no schedule fell behind', function (): void {
    $reader = htpsUser('phone-old');
    htpsSeedInbox($reader, lastScanAt: CarbonImmutable::now()->subHours(30)->toDateTimeString());

    putenv('NATIVEPHP_PLATFORM=android');
    $tile = htpsTile($reader);

    expect($tile->lines[0]->status)->toBe('unscheduled');
});

it('still reads a recent scan on a phone as healthy, because that scan really happened', function (): void {
    $reader = htpsUser('phone-recent');
    htpsSeedInbox($reader, lastScanAt: CarbonImmutable::now()->subHours(3)->toDateTimeString());

    putenv('NATIVEPHP_PLATFORM=ios');
    $tile = htpsTile($reader);

    expect($tile->lines[0]->status)->toBe('healthy')
        ->and($tile->overallStatus)->toBe('healthy');
});

it('still raises reauth above an unscheduled line on a phone', function (): void {
    $reader = htpsUser('phone-reauth');
    htpsSeedInbox($reader, email: 'first@example.com');
    htpsSeedInbox($reader, status: 'needs_reauth', email: 'second@example.com');

    putenv('NATIVEPHP_PLATFORM=ios');
    $tile = htpsTile($reader);

    expect($tile->lines[0]->status)->toBe('unscheduled')
        ->and($tile->lines[1]->status)->toBe('reauth')
        ->and($tile->overallStatus)->toBe('reauth');
});

it('paints the phone line with the neutral dot the copy beside it already promises', function (): void {
    $reader = htpsUser('phone-dot');
    htpsSeedInbox($reader);

    putenv('NATIVEPHP_PLATFORM=ios');
    $html = view('email-scan::livewire.email-scan-health-tile', ['tile' => htpsTile($reader)])->render();

    expect($html)->toContain('not scanned on this phone')
        ->and($html)->toContain('bg-slate-400')
        ->and($html)->not->toContain('bg-amber-700');
});

it('keeps the amber dot on the desktop, where a missing scan is a real fault', function (): void {
    $reader = htpsUser('desktop-dot');
    htpsSeedInbox($reader);

    $html = view('email-scan::livewire.email-scan-health-tile', ['tile' => htpsTile($reader)])->render();

    expect($html)->toContain('not scanned yet')
        ->and($html)->toContain('bg-amber-700')
        ->and($html)->not->toContain('bg-slate-400');
});
