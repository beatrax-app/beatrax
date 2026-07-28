<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\GraphApiClient;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

/**
 * GraphApiClient driven through a mocked transport.
 *
 * The HTTP boundary used to be a private factory whose comment offered it as a
 * seam "a future test subclass could override" — but the class is final, so
 * that subclass could never exist and everything past the SSRF guard went
 * untested. The client is injectable now, so the paging, the raw-message
 * fetch and the error mapping can be exercised without reaching Microsoft.
 */
beforeEach(function (): void {
    $this->secrets = new class extends OAuthSecretsRepository
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

    $this->oauth = new class extends MicrosoftOAuthProvider
    {
        public function __construct() {}
    };

    $this->clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::createFromTimestamp(time());
        }
    };

    $this->makeClient = function (array $responses): GraphApiClient {
        return new GraphApiClient(
            $this->secrets,
            $this->oauth,
            $this->clock,
            $this->createStub(EventsDispatcher::class),
            $this->createStub(DatabaseManager::class),
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]),
        );
    };
});

function graphJson(array $payload): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload));
}

it('returns the messages on a page and the link to the next one', function (): void {
    $client = ($this->makeClient)([
        graphJson([
            'value' => [
                ['id' => 'AAA', 'subject' => 'Receipt', 'receivedDateTime' => '2026-01-01T10:00:00Z',
                    'from' => ['emailAddress' => ['address' => 'billing@shop.example']]],
                ['id' => 'BBB', 'subject' => 'Invoice', 'receivedDateTime' => '2026-01-02T10:00:00Z',
                    'from' => ['emailAddress' => ['address' => 'billing@shop.example']]],
            ],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=abc',
        ]),
    ]);

    $page = $client->listSenderMessagesPaged(
        1,
        ['billing@shop.example'],
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        null,
    );

    expect($page['messages'])->toHaveCount(2)
        ->and($page['nextLink'])->toBe('https://graph.microsoft.com/v1.0/me/messages?$skiptoken=abc');
});

it('reports no next link on the last page, which is what ends a scan', function (): void {
    $client = ($this->makeClient)([
        graphJson(['value' => [
            ['id' => 'AAA', 'subject' => 'Receipt', 'receivedDateTime' => '2026-01-01T10:00:00Z',
                'from' => ['emailAddress' => ['address' => 'billing@shop.example']]],
        ]]),
    ]);

    $page = $client->listSenderMessagesPaged(
        1,
        ['billing@shop.example'],
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
        null,
    );

    // A nextLink that never becomes null is an infinite scan; the absence of
    // the key has to read as "done" rather than as an empty string.
    expect($page['nextLink'])->toBeNull()
        ->and($page['messages'])->toHaveCount(1);
});

it('asks for nothing at all when there are no senders to scan for', function (): void {
    // No HTTP responses queued: reaching the transport here would throw, which
    // is the assertion — an empty pattern list must not become an unfiltered
    // "fetch this mailbox" request.
    $client = ($this->makeClient)([]);

    $page = $client->listSenderMessagesPaged(1, [], new DateTimeImmutable('2026-01-01T00:00:00Z'), null);

    expect($page)->toBe(['messages' => [], 'nextLink' => null]);
});

it('fetches a raw message as bytes rather than a JSON envelope', function (): void {
    $raw = "From: billing@shop.example\r\nSubject: Receipt\r\n\r\nBody text";
    $client = ($this->makeClient)([
        new Response(200, ['Content-Type' => 'text/plain'], $raw),
    ]);

    expect($client->getRawMessage(1, 'AAA-BBB_CCC'))->toBe($raw);
});

it('refuses a message id that could carry a path traversal', function (string $id): void {
    $client = ($this->makeClient)([]);

    $client->getRawMessage(1, $id);
})->with([
    'traversal' => ['../../me/messages'],
    'slash' => ['AAA/BBB'],
    'space' => ['AAA BBB'],
])->throws(RuntimeException::class, 'allow-list validation');

it('carries the delta link forward so the next scan resumes where this one stopped', function (): void {
    $client = ($this->makeClient)([
        graphJson([
            'value' => [
                ['id' => 'AAA', 'subject' => 'Receipt', 'receivedDateTime' => '2026-01-01T10:00:00Z',
                    'from' => ['emailAddress' => ['address' => 'billing@shop.example']]],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/messages/delta?$deltatoken=xyz',
        ]),
    ]);

    $result = $client->deltaPage(1, null);

    expect($result['deltaLink'])->toBe('https://graph.microsoft.com/v1.0/me/messages/delta?$deltatoken=xyz');
});
