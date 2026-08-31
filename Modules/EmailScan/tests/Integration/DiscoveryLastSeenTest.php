<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\FakeGmailApiClient;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Public\Services\DiscoveredSenderQuery;

uses(RefreshDatabase::class);

// Graph rejects $orderby alongside $search, so discovery results arrive in no
// particular order. Stamping last_seen_at from whichever message came last
// walks the value BACKWARDS, and DiscoveredSenderQuery's 90-day window then
// drops the sender on the very pass that took it to MIN_OCCURRENCES.

function emailScanDiscoveryInbox(string $username): array
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => $username.'@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $user->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$user, $inboxId, $db];
}

function emailScanRunDiscovery(User $user, array $candidates): void
{
    $gmail = new FakeGmailApiClient(app(Filesystem::class));
    $gmail->queueDiscoveryResponse($candidates, nextPageToken: null);
    app()->instance(GmailApiClientContract::class, $gmail);
    app()->instance(GraphApiClientContract::class, new FakeGraphApiClient(app(Filesystem::class)));

    /** @var DiscoveryScanJob $job */
    $job = app()->make(DiscoveryScanJob::class, ['userId' => $user->id]);
    app()->call([$job, 'handle']);
}

it('never walks last_seen_at backwards when an older message arrives second', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 21, 12, 0, 0, 'UTC'));

    [$user, $inboxId, $db] = emailScanDiscoveryInbox('discovery-last-seen');

    emailScanRunDiscovery($user, [
        ['id' => 'newest', 'fromAddress' => 'billing@shop.example', 'fromName' => 'Shop', 'internalDate' => '2026-08-20T09:00:00Z'],
    ]);
    emailScanRunDiscovery($user, [
        ['id' => 'older', 'fromAddress' => 'billing@shop.example', 'fromName' => 'Shop', 'internalDate' => '2026-01-02T09:00:00Z'],
    ]);

    $row = $db->connection()->table('discovered_senders')
        ->where('inbox_id', $inboxId)
        ->where('sender_email', 'billing@shop.example')
        ->first(['occurrence_count', 'last_seen_at']);

    /** @var DiscoveredSenderQuery $query */
    $query = $this->app->make(DiscoveredSenderQuery::class);
    $candidates = array_map(
        static fn ($c): string => $c->senderEmail,
        $query->candidatesForUser($user),
    );

    expect((int) $row->occurrence_count)->toBe(2)
        ->and((string) $row->last_seen_at)->toStartWith('2026-08-20')
        ->and($candidates)->toBe(['billing@shop.example']);

    CarbonImmutable::setTestNow();
});

it('still advances last_seen_at when the newer message arrives second', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 21, 12, 0, 0, 'UTC'));

    [$user, $inboxId, $db] = emailScanDiscoveryInbox('discovery-last-seen-forward');

    emailScanRunDiscovery($user, [
        ['id' => 'older', 'fromAddress' => 'billing@shop.example', 'fromName' => 'Shop', 'internalDate' => '2026-01-02T09:00:00Z'],
    ]);
    emailScanRunDiscovery($user, [
        ['id' => 'newest', 'fromAddress' => 'billing@shop.example', 'fromName' => 'Shop', 'internalDate' => '2026-08-20T09:00:00Z'],
    ]);

    $row = $db->connection()->table('discovered_senders')
        ->where('inbox_id', $inboxId)
        ->where('sender_email', 'billing@shop.example')
        ->first(['last_seen_at']);

    expect((string) $row->last_seen_at)->toStartWith('2026-08-20');

    CarbonImmutable::setTestNow();
});
