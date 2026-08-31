<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;

uses(RefreshDatabase::class);

// Graph splits a delta across pages the same way it splits a message list:
// only the LAST page carries @odata.deltaLink, the ones before it carry
// @odata.nextLink. Reading one page and stopping loses the tail AND never
// advances the cursor, so the mailbox freezes with status=idle.

beforeEach(function (): void {
    Sleep::fake();

    $this->inboxRoot = storage_path('app/inbox');
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

afterEach(function (): void {
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

function emailScanGraphDeltaMessage(string $id, string $address, string $received): array
{
    return [
        'id' => $id,
        'subject' => 'Receipt',
        'receivedDateTime' => $received,
        'from' => ['emailAddress' => ['name' => 'Sender', 'address' => $address]],
    ];
}

function emailScanGraphDeltaInbox(string $username, ?string $deltaLink): array
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
        'provider' => 'microsoft',
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
        'last_delta_link' => $deltaLink,
        'last_scan_at' => $now,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$user, $inboxId, $db];
}

it('incremental: follows a delta nextLink so the tail of a two-page delta is not lost', function (): void {
    [$user, $inboxId, $db] = emailScanGraphDeltaInbox('graph-delta-tail', 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=T0');

    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fake->queueDeltaResponse(
        [emailScanGraphDeltaMessage('paypal-page1', 'service@paypal.com', '2026-05-11T09:14:21Z')],
        deltaLink: null,
        nextLink: 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=S1',
    );
    $fake->queueDeltaResponse(
        [emailScanGraphDeltaMessage('ics-page2', 'noreply@ics.nl', '2026-05-12T06:00:13Z')],
        deltaLink: 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=T1',
    );
    $this->app->instance(GraphApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    $stored = $db->connection()->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->orderBy('provider_message_id')
        ->pluck('provider_message_id')
        ->all();

    $state = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['last_delta_link', 'status']);

    expect($stored)->toBe(['ics-page2', 'paypal-page1'])
        ->and($state->last_delta_link)->toBe('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=T1')
        ->and($state->status)->toBe('idle');

    // The second page has to be asked for with the nextLink the first returned.
    $deltaCalls = array_values(array_filter(
        $fake->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'deltaPage',
    ));
    expect($deltaCalls)->toHaveCount(2)
        ->and($deltaCalls[1]['args']['deltaLink'])->toBe('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=S1');
});

it('backfill: follows the baseline delta nextLink so a paginated baseline still writes a cursor', function (): void {
    [$user, $inboxId, $db] = emailScanGraphDeltaInbox('graph-delta-baseline', null);

    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fake->queueSenderPage([], nextLink: null);
    $fake->queueDeltaResponse([], deltaLink: null, nextLink: 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=B1');
    $fake->queueDeltaResponse([], deltaLink: 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=BASE1');
    $this->app->instance(GraphApiClientContract::class, $fake);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    $state = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['last_delta_link', 'status']);

    expect($state->last_delta_link)->toBe('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=BASE1')
        ->and($state->status)->toBe('idle');
});

// The Microsoft delta walk has no Gmail-style historyId recovery: a cursor
// that never lands leaves runMicrosoftIncremental returning immediately on
// every tick from then on.
it('incremental: a delta walk with no cursor written does not silently succeed', function (): void {
    [$user, $inboxId, $db] = emailScanGraphDeltaInbox('graph-delta-hop', 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=T0');

    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GraphApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    // The default fixture is a one-page delta carrying one allow-listed
    // message — coverage the fake could not express while every deltaPage
    // call answered the empty baseline body.
    $stored = $db->connection()->table('inbox_messages')->where('inbox_id', $inboxId)->pluck('provider_message_id')->all();
    $state = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['last_delta_link']);

    expect($stored)->toBe(['ics-sample-statement-notice'])
        ->and($state->last_delta_link)->toBe('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=walked-abc');
});
