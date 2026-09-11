<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphErrorMapper;
use Modules\EmailScan\Internal\Clients\MessageUnavailableException;
use Modules\EmailScan\Internal\Exceptions\ProviderTransportException;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

uses(RefreshDatabase::class);

// Microsoft Graph message ids are mutable: an ordinary inbox rule moving a
// message changes the id, and the client sends no Prefer: IdType header that
// would make them stable. The delta page hands over an id, the fetch for it
// answers 404, and that 404 had no arm in GraphErrorMapper -- so it left the
// walk as a plain failure, before the cursor write at the end of it. The
// mailbox then replays the same delta link on every tick from then on.

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

function frozenDeltaGraphClient(FakeGraphApiClient $inner, string $messageId, Throwable $failure): GraphApiClientContract
{
    return new class($inner, $messageId, $failure) implements GraphApiClientContract
    {
        public function __construct(
            private FakeGraphApiClient $inner,
            private string $messageId,
            private Throwable $failure,
        ) {}

        /**
         * @param  list<string>  $senderPatterns
         * @return array{messages: list<array<string, mixed>>, nextLink: ?string}
         */
        public function listSenderMessagesPaged(int $inboxId, array $senderPatterns, DateTimeImmutable $windowStart, ?string $nextLink): array
        {
            return $this->inner->listSenderMessagesPaged($inboxId, $senderPatterns, $windowStart, $nextLink);
        }

        public function getRawMessage(int $inboxId, string $providerMessageId): string
        {
            if ($providerMessageId === $this->messageId) {
                throw $this->failure;
            }

            return $this->inner->getRawMessage($inboxId, $providerMessageId);
        }

        /**
         * @return array{messages: list<array<string, mixed>>, deltaLink: ?string, nextLink: ?string}
         */
        public function deltaPage(int $inboxId, ?string $deltaLink, ?DateTimeImmutable $sinceOverride = null): array
        {
            return $this->inner->deltaPage($inboxId, $deltaLink, $sinceOverride);
        }

        /**
         * @param  list<string>  $keywords
         * @param  list<string>  $excludeSenders
         * @return array{messages: list<array<string, mixed>>, nextLink: ?string}
         */
        public function listDiscoveryCandidatesPaged(int $inboxId, array $keywords, array $excludeSenders, ?string $nextLink): array
        {
            return $this->inner->listDiscoveryCandidatesPaged($inboxId, $keywords, $excludeSenders, $nextLink);
        }
    };
}

function frozenDeltaMessage(string $id): array
{
    return [
        'id' => $id,
        'subject' => 'Receipt',
        'receivedDateTime' => '2026-05-11T09:14:21Z',
        'from' => ['emailAddress' => ['name' => 'PayPal', 'address' => 'service@paypal.com']],
    ];
}

function frozenDeltaInbox(string $username, string $deltaLink): array
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
        'status' => InboxScanStatus::Idle->value,
        'last_delta_link' => $deltaLink,
        'last_scan_at' => $now,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$user, $inboxId, $db];
}

function frozenDeltaRealClient(Response $response): GraphApiClient
{
    $secrets = new class extends OAuthSecretsRepository
    {
        public function __construct() {}

        public function loadInbox(int $inboxId): ?InboxCredentials
        {
            return new InboxCredentials(
                inboxId: $inboxId,
                provider: 'microsoft',
                refreshToken: 'fixture-refresh',
                scope: 'Mail.Read offline_access User.Read',
                expiresAt: (new DateTimeImmutable)->setTimestamp(time() + 3600),
                accessToken: 'fixture-access-token',
            );
        }
    };

    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::createFromTimestamp(time());
        }
    };

    return new GraphApiClient(
        $secrets,
        new class extends MicrosoftOAuthProvider
        {
            public function __construct() {}
        },
        $clock,
        app(EventsDispatcher::class),
        app(DatabaseManager::class),
        new GraphErrorMapper($clock),
        new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([$response]))]),
    );
}

it('answers a 404 on a single-message fetch with the sentinel a walk can skip', function (): void {
    $client = frozenDeltaRealClient(new Response(
        404,
        ['Content-Type' => 'application/json'],
        (string) json_encode(['error' => ['code' => 'ErrorItemNotFound', 'message' => 'The specified object was not found in the store.']]),
    ));

    expect(fn (): string => $client->getRawMessage(1, 'AAMkAGI2THEVANISHEDID'))
        ->toThrow(MessageUnavailableException::class, 'The specified object was not found in the store.');
});

// The gating control: the same status on a collection is an endpoint that
// moved, not a message that went. Mapping every 404 to the skip sentinel would
// walk straight past a Graph route this build no longer reaches.
it('leaves a 404 on a collection call as the plain failure it is', function (): void {
    $client = frozenDeltaRealClient(new Response(404, ['Content-Type' => 'application/json'], (string) json_encode(['error' => ['message' => 'Resource not found for the segment.']])));

    expect(fn (): array => $client->listSenderMessagesPaged(1, ['service@paypal.com'], new DateTimeImmutable('2026-05-01T00:00:00Z'), null))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => $client->listSenderMessagesPaged(1, ['service@paypal.com'], new DateTimeImmutable('2026-05-01T00:00:00Z'), null))
        ->not->toThrow(MessageUnavailableException::class);
});

it('advances the delta link past a message the mailbox no longer holds', function (): void {
    [$user, $inboxId, $db] = frozenDeltaInbox('graph-404-skip', 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=T0');

    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fake->queueDeltaResponse(
        [frozenDeltaMessage('paypal-first'), frozenDeltaMessage('paypal-moved'), frozenDeltaMessage('paypal-last')],
        deltaLink: 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=T1',
    );
    $this->app->instance(
        GraphApiClientContract::class,
        frozenDeltaGraphClient($fake, 'paypal-moved', new MessageUnavailableException('Microsoft Graph no longer holds the message: ErrorItemNotFound')),
    );

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    $stored = $db->connection()->table('inbox_messages')->where('inbox_id', $inboxId)->orderBy('provider_message_id')->pluck('provider_message_id')->all();
    $state = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['last_delta_link', 'status']);

    expect($stored)->toBe(['paypal-first', 'paypal-last'])
        ->and($state->last_delta_link)->toBe('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=T1')
        ->and($state->status)->toBe(InboxScanStatus::Idle->value);
});

// The positive control for the assertion above. A stop is exactly what has to
// happen when the failure is one a later attempt could clear -- skipping there
// would move the cursor past a receipt this device could still have had -- so
// this proves the cursor assertion can see a walk that stopped.
it('keeps the delta link where it was when the failure is one a retry could clear', function (): void {
    [$user, $inboxId, $db] = frozenDeltaInbox('graph-transport-stop', 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=T0');

    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fake->queueDeltaResponse(
        [frozenDeltaMessage('paypal-first'), frozenDeltaMessage('paypal-broken'), frozenDeltaMessage('paypal-last')],
        deltaLink: 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=T1',
    );
    $this->app->instance(
        GraphApiClientContract::class,
        frozenDeltaGraphClient($fake, 'paypal-broken', new ProviderTransportException('GraphApiClient: HTTP error fetching raw message.')),
    );

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);

    expect(fn (): mixed => $this->app->call([$job, 'handle']))->toThrow(ProviderTransportException::class);

    $stored = $db->connection()->table('inbox_messages')->where('inbox_id', $inboxId)->pluck('provider_message_id')->all();
    $state = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['last_delta_link', 'status']);

    expect($stored)->toBe(['paypal-first'])
        ->and($state->last_delta_link)->toBe('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=T0')
        ->and($state->status)->toBe(InboxScanStatus::Error->value);
});
