<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Commands\RelayServeCommand;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayDrainRegistry;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\Relay\RelayRateLimiter;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;
use Modules\Sync\Tests\Support\RelayHandlerHarness;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

/**
 * The relay holds no key and performs no cryptographic operation: it stores and
 * forwards opaque bytes. Asserting that against random_bytes() proves nothing —
 * random bytes are not JSON and contain no field name whatever the relay does.
 * These cases push a blob that IS readable structure through the real client,
 * the real serve handler and the real mailbox, so any decrypt, parse or reshape
 * shows up as bytes that changed. The source guard below reads the relay's own
 * code for the two operations it must never contain.
 *
 * @return list<string> absolute paths of every file that IS the relay
 */
function relayZeroKnowledgeSources(): array
{
    $files = glob(base_path('Modules/Sync/Internal/Transport/Relay/*.php')) ?: [];
    $files[] = base_path('Modules/Sync/Commands/RelayServeCommand.php');
    sort($files);

    return array_values($files);
}

/**
 * @param  list<string>  $paths
 * @return list<string> one entry per banned operation found, `file:line reason`
 */
function relayZeroKnowledgeViolations(array $paths): array
{
    $hits = [];

    foreach ($paths as $path) {
        // Comments are dropped first: the invariant is written down in prose
        // inside RelayMailbox and RelayServeCommand, and a scanner that reads
        // it would flag the very sentence forbidding the call.
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            // A literal reaches sodium through call_user_func just as well as a
            // direct call does.
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING && stripos($token[1], 'sodium_') !== false) {
                $hits[] = "{$path}:{$token[2]} names sodium_ in a string literal";

                continue;
            }

            if ($token[0] !== T_STRING) {
                continue;
            }

            if (str_starts_with(strtolower($token[1]), 'sodium_')) {
                $hits[] = "{$path}:{$token[2]} calls {$token[1]}()";

                continue;
            }

            // json_decode of the request envelope is how the relay reads its
            // routing fields and is fine; json_decode of a blob is the relay
            // looking inside the ciphertext.
            if (strtolower($token[1]) === 'json_decode'
                && preg_match('/blob/i', relayZeroKnowledgeCallArgs($tokens, $index)) === 1) {
                $hits[] = "{$path}:{$token[2]} json_decode()s a blob";
            }
        }
    }

    return $hits;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return string the source text of the call's argument list, or '' when the
 *                name is not a call at all
 */
function relayZeroKnowledgeCallArgs(array $tokens, int $index): string
{
    $depth = 0;
    $args = '';

    for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];
        $text = is_array($token) ? $token[1] : $token;

        if ($depth === 0) {
            if ($text === '(') {
                $depth = 1;

                continue;
            }
            if (trim($text) !== '') {
                return '';
            }

            continue;
        }

        if ($text === '(') {
            $depth++;
        } elseif ($text === ')') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }

        $args .= $text;
    }

    return $args;
}

/** @return string a blob that is readable structure, so a relay that parsed it would show */
function relayZeroKnowledgeLegibleBlob(): string
{
    return '{"table":"transactions","note":"secret-note","user_id":7,"category_id":3}'
        ."\x00\xff\xfe\x01"
        .random_bytes(32);
}

beforeEach(function (): void {
    $this->relayConfig = new RelayConfig;
    $this->relayConfig->setEndpointUrl('https://relay.test');
    $this->relayConfig->setAuthToken('relay-shared-secret');

    $this->mailbox = new RelayMailbox(app(DatabaseManager::class), app(Clock::class));

    $command = new RelayServeCommand(
        new NullLogger,
        $this->mailbox,
        new RelayDrainRegistry,
        new RelayRateLimiter(app(Clock::class)),
        new RelayTlsMaterial,
        new DaemonShutdownSignal,
    );

    $this->relayClient = new RelayClient(
        RelayHandlerHarness::httpFactory($command),
        $this->relayConfig,
        new NullLogger,
    );
});

afterEach(function (): void {
    $secrets = UserDataPathService::secretsPath();

    foreach ([
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-token.json',
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-drain-secret.json',
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-drain-registry.json',
        UserDataPathService::appPath('sync/relay.json'),
    ] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

it('carries a legible blob through deliver, store and drain without altering one byte', function (): void {
    $blob = relayZeroKnowledgeLegibleBlob();

    $this->relayClient->deliver('device-sender', 'device-recipient', $blob);

    $stored = DB::table('relay_mailbox')->where('recipient_did', 'device-recipient')->first();
    expect($stored)->not->toBeNull();
    expect($stored->blob)->toBe(
        $blob,
        'the relay stored something other than the bytes it was handed — it parsed, decoded or re-encoded the blob',
    );

    $drained = $this->relayClient->drain('device-recipient', $this->relayConfig->deviceDrainSecret());
    expect($drained)->toHaveCount(1);
    expect(base64_decode((string) $drained[0]['blob'], true))->toBe(
        $blob,
        'the drained bytes differ from the delivered bytes — the relay is not a conduit',
    );
});

it('writes only routing metadata when the mailbox itself stores a blob', function (): void {
    $blob = relayZeroKnowledgeLegibleBlob();

    $this->mailbox->deliver('device-a', 'device-b', $blob);

    $row = (array) DB::table('relay_mailbox')->where('recipient_did', 'device-b')->first();

    expect(array_keys($row))->toBe(
        ['id', 'sender_did', 'recipient_did', 'blob', 'created_at', 'delivered_at', 'expires_at'],
        'a new relay_mailbox column is a new thing the relay operator can read',
    );

    // The blob's own contents must not have been lifted out into a column the
    // operator can query. Everything but the blob is routing metadata.
    unset($row['blob']);
    foreach ($row as $column => $value) {
        expect((string) $value)->not->toContain('secret-note', "relay_mailbox.{$column} leaks blob content");
    }
});

it('sends the blob to the relay verbatim and puts nothing else in the envelope', function (): void {
    $blob = relayZeroKnowledgeLegibleBlob();

    $capturing = new HttpFactory;
    $capturing->fake(fn () => HttpFactory::response('{"status":"accepted"}', 202));

    (new RelayClient($capturing, $this->relayConfig, new NullLogger))
        ->deliver('device-sender', 'device-recipient', $blob);

    $recorded = $capturing->recorded();
    expect($recorded)->toHaveCount(1);

    /** @var array<string, mixed> $sent */
    $sent = $recorded[0][0]->data();
    expect(array_keys($sent))->toBe(['sender_did', 'recipient_did', 'blob']);
    expect(base64_decode((string) $sent['blob'], true))->toBe(
        $blob,
        'RelayClient must forward the blob it was handed, unmodified',
    );
});

it('contains no sodium call and no json_decode of a blob anywhere in the relay', function (): void {
    $sources = relayZeroKnowledgeSources();
    expect($sources)->not->toBeEmpty();

    expect(relayZeroKnowledgeViolations($sources))->toBe(
        [],
        'The relay performs no cryptographic operation and never looks inside a blob.',
    );
});

it('detects a sodium call and a blob json_decode when one is present', function (): void {
    // Without this the guard above passes on a clean tree whether or not it can
    // see anything at all, which is the failure mode it replaced.
    $planted = tempnam(sys_get_temp_dir(), 'relay-zk').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        // sodium_crypto_box_open() and json_decode($blob) named in a comment.
        final class PlantedRelayViolation
        {
            public function open(string $blob): mixed
            {
                $plain = sodium_crypto_secretbox_open($blob, '', '');

                return json_decode($plain === false ? $blob : $plain, true);
            }
        }
        PHP);

    try {
        $found = relayZeroKnowledgeViolations([$planted]);
    } finally {
        @unlink($planted);
    }

    expect($found)->toHaveCount(2);
    expect(implode("\n", $found))->toContain('sodium_crypto_secretbox_open')->toContain('json_decode()s a blob');
});
