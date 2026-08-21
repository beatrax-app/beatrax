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

uses(RefreshDatabase::class);

// Ignoring nextPageToken / nextLink left any sender past the first 100 newest
// broad-keyword matches invisible to the discovery loop. DISCOVERY_MAX_PAGES
// bounds the other direction: a runaway infinite-cursor response stops at 10
// pages rather than burning the worker's heap.

beforeEach(function (): void {
    $this->path = storage_path('app/secrets/email-oauth.json');
    if (is_file($this->path)) {
        @unlink($this->path);
    }
});

afterEach(function (): void {
    if (is_file($this->path)) {
        @unlink($this->path);
    }
});

it('walks multiple Gmail discovery pages, capturing senders past the first 100', function (): void {
    $user = User::query()->create([
        'username' => 'gmail-discovery-pagination',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'gmail-discovery-pagination@example.com',
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

    $fakeGmail = new FakeGmailApiClient($this->app->make(Filesystem::class));
    // One candidate per page, so a single-page walk would find only the first.
    $fakeGmail->queueDiscoveryResponse(
        [['id' => 'm-1', 'fromAddress' => 'page1-sender@example.com', 'fromName' => 'Page 1 Sender', 'internalDate' => '2026-05-10T00:00:00Z']],
        nextPageToken: 'page-2-token',
    );
    $fakeGmail->queueDiscoveryResponse(
        [['id' => 'm-2', 'fromAddress' => 'page2-sender@example.com', 'fromName' => 'Page 2 Sender', 'internalDate' => '2026-05-11T00:00:00Z']],
        nextPageToken: 'page-3-token',
    );
    $fakeGmail->queueDiscoveryResponse(
        [['id' => 'm-3', 'fromAddress' => 'page3-sender@example.com', 'fromName' => 'Page 3 Sender', 'internalDate' => '2026-05-12T00:00:00Z']],
        nextPageToken: null,
    );
    $this->app->instance(GmailApiClientContract::class, $fakeGmail);

    $fakeGraph = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GraphApiClientContract::class, $fakeGraph);

    /** @var DiscoveryScanJob $job */
    $job = $this->app->make(DiscoveryScanJob::class, ['userId' => $user->id]);
    $this->app->call([$job, 'handle']);

    $rows = $db->connection()
        ->table('discovered_senders')
        ->where('inbox_id', $inboxId)
        ->orderBy('sender_email', 'asc')
        ->get();
    expect($rows)->toHaveCount(3);

    $bySender = [];
    foreach ($rows as $row) {
        $bySender[(string) $row->sender_email] = $row;
    }
    expect($bySender)->toHaveKeys([
        'page1-sender@example.com',
        'page2-sender@example.com',
        'page3-sender@example.com',
    ]);

    $calls = array_values(array_filter(
        $fakeGmail->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'listDiscoveryCandidates',
    ));
    expect($calls)->toHaveCount(3);
    expect($calls[0]['args']['pageToken'])->toBeNull();
    expect($calls[1]['args']['pageToken'])->toBe('page-2-token');
    expect($calls[2]['args']['pageToken'])->toBe('page-3-token');
});

it('walks multiple Microsoft Graph discovery pages, capturing senders past the first 100', function (): void {
    $user = User::query()->create([
        'username' => 'graph-discovery-pagination',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'microsoft',
        'email' => 'graph-discovery-pagination@example.com',
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

    $fakeGraph = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fakeGraph->queueDiscoveryResponse(
        [['id' => 'g-1', 'from' => ['emailAddress' => ['address' => 'page1@graph.example', 'name' => 'P1']], 'receivedDateTime' => '2026-05-10T00:00:00Z']],
        nextLink: 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p2',
    );
    $fakeGraph->queueDiscoveryResponse(
        [['id' => 'g-2', 'from' => ['emailAddress' => ['address' => 'page2@graph.example', 'name' => 'P2']], 'receivedDateTime' => '2026-05-11T00:00:00Z']],
        nextLink: null,
    );
    $this->app->instance(GraphApiClientContract::class, $fakeGraph);

    $fakeGmail = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $fakeGmail);

    /** @var DiscoveryScanJob $job */
    $job = $this->app->make(DiscoveryScanJob::class, ['userId' => $user->id]);
    $this->app->call([$job, 'handle']);

    $rows = $db->connection()
        ->table('discovered_senders')
        ->where('inbox_id', $inboxId)
        ->orderBy('sender_email', 'asc')
        ->get();
    expect($rows)->toHaveCount(2);

    $bySender = [];
    foreach ($rows as $row) {
        $bySender[(string) $row->sender_email] = $row;
    }
    expect($bySender)->toHaveKeys([
        'page1@graph.example',
        'page2@graph.example',
    ]);

    $calls = array_values(array_filter(
        $fakeGraph->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'listDiscoveryCandidatesPaged',
    ));
    expect($calls)->toHaveCount(2);
    expect($calls[0]['args']['nextLink'])->toBeNull();
    expect($calls[1]['args']['nextLink'])->toBe('https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p2');
});

it('caps Gmail discovery at DISCOVERY_MAX_PAGES (10) when the provider hands back an infinite cursor', function (): void {
    $user = User::query()->create([
        'username' => 'gmail-discovery-cap',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'gmail-discovery-cap@example.com',
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

    $fakeGmail = new FakeGmailApiClient($this->app->make(Filesystem::class));
    // Twenty pages that never stop offering a next token, against a cap of 10.
    for ($i = 0; $i < 20; $i++) {
        $fakeGmail->queueDiscoveryResponse(
            [[
                'id' => 'm-cap-'.$i,
                'fromAddress' => 'cap-'.$i.'@example.com',
                'fromName' => 'Cap '.$i,
                'internalDate' => '2026-05-10T00:00:00Z',
            ]],
            nextPageToken: 'next-'.$i,
        );
    }
    $this->app->instance(GmailApiClientContract::class, $fakeGmail);

    $fakeGraph = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GraphApiClientContract::class, $fakeGraph);

    /** @var DiscoveryScanJob $job */
    $job = $this->app->make(DiscoveryScanJob::class, ['userId' => $user->id]);
    $this->app->call([$job, 'handle']);

    $calls = array_values(array_filter(
        $fakeGmail->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'listDiscoveryCandidates',
    ));
    expect(count($calls))->toBe(10, 'Discovery must respect DISCOVERY_MAX_PAGES hard cap.');

    $rowCount = $db->connection()
        ->table('discovered_senders')
        ->where('inbox_id', $inboxId)
        ->count();
    // One sender per page, so the row count is the page count.
    expect($rowCount)->toBe(10);
});
