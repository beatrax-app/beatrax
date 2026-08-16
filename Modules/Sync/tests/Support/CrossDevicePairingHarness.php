<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Amp\Http\Server\Driver\Client as AmpClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use League\Uri\Http as HttpUri;
use Mockery;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Commands\RelayServeCommand;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;
use Psr\Log\NullLogger;
use ReflectionMethod;

use function Amp\ByteStream\buffer;

/**
 * Two-separate-database + fake-relay test harness (Phase 15 HIGH-01,
 * `15-crossdevice-pairing-PLAN.md` §4).
 *
 * The single-DB pairing-test convention elsewhere in this suite (one
 * database, distinct device_ids on the SAME `pairing_tokens` row) is exactly
 * what masked HIGH-01: `confirm()` against a shared row can never prove the
 * cross-device relay propagation actually reaches a genuinely SEPARATE
 * database. This trait configures THREE physically distinct SQLite
 * connections — `desktop`, `phone` (each device's own local DB — see
 * {@see self::asDevice()}) and `relay` (the zero-knowledge mailbox store,
 * reachable by both but never holding either device's `pairing_tokens`) —
 * and fakes the relay's HTTP surface by routing `RelayClient`'s outbound
 * requests through the REAL `RelayServeCommand::route()` handler (mirrors
 * `RelayRoundTripTest`'s established fake-Illuminate-HTTP-Factory precedent)
 * so the real CR-01..CR-04 wire contract + per-device drain authorization
 * are exercised for real, not re-implemented as a test double.
 */
trait CrossDevicePairingHarness
{
    private RelayConfig $harnessRelayConfig;

    /**
     * Configure the three named connections, migrate the desktop/phone
     * pairing schema onto each, and install the fake relay HTTP transport.
     * Call once per test, before any device-role code runs.
     */
    protected function crossDevicePairingSetUp(): void
    {
        /** @var DatabaseManager $db */
        $db = $this->app->make(DatabaseManager::class);

        foreach (['desktop', 'phone', 'relay'] as $name) {
            config(["database.connections.{$name}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
            $db->purge($name);
        }

        $this->harnessMigratePairingSchema($db, 'desktop');
        $this->harnessMigratePairingSchema($db, 'phone');
        $this->harnessMigrateRelaySchema($db, 'relay');

        // A real production database carries a `relay_mailbox` table
        // regardless of whether that device runs `relay:serve` itself —
        // GdkRotationService writes GDK_EPOCH_WRAP entries into the LOCAL
        // relay_mailbox (an outbox for both a live LAN push and eventual
        // relay forwarding), distinct from the `relay` connection above
        // (the transport-level ZK mailbox the fake RelayServeCommand
        // handler reads/writes for PAIRING frames). Both device
        // connections need their own copy so GDK epoch fan-out tests
        // (case 8) do not fail on a missing table.
        $this->harnessMigrateRelaySchema($db, 'desktop');
        $this->harnessMigrateRelaySchema($db, 'phone');

        $this->harnessRelayConfig = new RelayConfig;
        $this->harnessRelayConfig->setEndpointUrl('https://relay.test');
        $this->harnessRelayConfig->setAuthToken('cross-device-harness-relay-secret');

        $mailbox = new RelayMailbox($db, $this->app->make(Clock::class));
        // The harness drives the relay in-process over a fake HTTP factory, so
        // it never binds a socket and needs no TLS material of its own.
        $command = new RelayServeCommand(
            new NullLogger,
            $mailbox,
            $this->harnessRelayConfig,
            new RelayTlsMaterial,
            new DaemonShutdownSignal,
        );

        $relayClient = new RelayClient(
            $this->harnessRelayHttpFactory($command, $db),
            $this->harnessRelayConfig,
            new NullLogger,
        );

        // Rebind the SINGLETON so any container resolution (Livewire
        // components, PairingRelayCourier resolved via app()) receives this
        // fake-transport-backed client instead of a real network call.
        $this->app->instance(RelayClient::class, $relayClient);
    }

    /**
     * Run $fn with the DatabaseManager's default connection swapped to
     * $connection ('desktop' or 'phone') — every collaborator in this
     * codebase resolves its storage via `$this->db->connection()` (the
     * DEFAULT connection), so this is what makes "device acts on its own
     * DB" faithful without threading a connection name through every
     * service signature (mirrors the plan's own documented technique).
     * Restores the previous default connection afterward, even on
     * exception.
     */
    protected function asDevice(string $connection, Closure $fn): mixed
    {
        /** @var DatabaseManager $db */
        $db = $this->app->make(DatabaseManager::class);
        $previous = $db->getDefaultConnection();
        $db->setDefaultConnection($connection);

        try {
            return $fn();
        } finally {
            $db->setDefaultConnection($previous);
        }
    }

    /**
     * Delete the on-disk relay config + token artifacts this trait wrote
     * (mirrors `RelayRoundTripTest::afterEach()`) — call from the consuming
     * test file's own `afterEach()` so no relay configuration leaks into an
     * unrelated later test in the same process.
     */
    protected function crossDevicePairingTearDown(): void
    {
        $tokenPath = UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.'sync-relay-token.json';
        $relayPath = UserDataPathService::appPath('sync/relay.json');

        foreach ([$tokenPath, $relayPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Build an HttpFactory whose requests are routed through the REAL
     * RelayServeCommand handler instead of the network — identical wiring
     * to `RelayRoundTripTest::relayServerHttpFactory()`, with one addition:
     * the DatabaseManager default connection is swapped to `relay` for the
     * duration of the handler invocation, so `RelayMailbox`'s internal
     * `$this->db->connection()` calls land on the dedicated relay store
     * rather than whichever device connection happened to be active when
     * `RelayClient::deliver()/drain()/confirm()` was called.
     */
    private function harnessRelayHttpFactory(RelayServeCommand $command, DatabaseManager $db): HttpFactory
    {
        $factory = new HttpFactory;

        $route = new ReflectionMethod($command, 'route');

        $factory->fake(function (ClientRequest $request) use ($command, $route, $db) {
            $previous = $db->getDefaultConnection();
            $db->setDefaultConnection('relay');

            try {
                $method = $request->method();
                $url = $request->url();

                $ampClient = Mockery::mock(AmpClient::class);
                $ampUri = HttpUri::new($url);

                $headers = [];
                if ($request->hasHeader('Authorization')) {
                    $headers['authorization'] = $request->header('Authorization')[0];
                }

                $body = $request->body();

                $ampRequest = new AmpRequest($ampClient, $method, $ampUri, $headers, $body);

                /** @var AmpResponse $ampResponse */
                $ampResponse = $route->invoke($command, $ampRequest);

                $status = $ampResponse->getStatus();
                $respBody = buffer($ampResponse->getBody());

                return HttpFactory::response($respBody, $status, [
                    'Content-Type' => 'application/json',
                ]);
            } finally {
                $db->setDefaultConnection($previous);
            }
        });

        return $factory;
    }

    /**
     * Mirror the production `pairing_tokens` + `device_registry` +
     * `sync_encryption_state` schema onto $connection — a genuinely separate
     * SQLite database from the app's own default test connection.
     */
    private function harnessMigratePairingSchema(DatabaseManager $db, string $connection): void
    {
        $schema = $db->connection($connection)->getSchemaBuilder();

        $schema->create('pairing_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('token_hash')->unique();
            $table->string('initiator_device_id');
            $table->string('initiator_ed25519_pub_hex');
            $table->string('initiator_x25519_pub_hex');
            $table->string('responder_device_id')->nullable();
            $table->string('responder_ed25519_pub_hex')->nullable();
            $table->string('responder_x25519_pub_hex')->nullable();
            // Mirrors the pairing_tokens migration: the responder's own device
            // name rides here from accept until admission.
            $table->string('responder_name')->nullable();
            $table->string('state')->default('pending');
            $table->text('expires_at');
            $table->text('accepted_at')->nullable();
            $table->text('initiator_confirmed_at')->nullable();
            $table->text('responder_confirmed_at')->nullable();
            $table->text('initiator_seeded_at')->nullable();
            // Mirrors the migration too: the initiator's own name rides from
            // the scanned QR until admission, so the peer is labelled with
            // what it calls itself rather than a placeholder.
            $table->string('initiator_name')->nullable();
            $table->text('created_at');
        });

        $db->connection($connection)->statement(
            'CREATE INDEX pairing_tokens_user_expires_idx ON pairing_tokens (user_id, expires_at, state)'
        );

        $schema->create('device_registry', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('device_id');
            $table->string('name');
            $table->string('ed25519_public_key_hex');
            $table->string('x25519_public_key_hex');
            $table->text('safety_number_words');
            $table->integer('is_self')->default(0);
            $table->text('paired_at');
            $table->text('confirmed_at')->nullable();
            $table->text('last_seen_at')->nullable();
            $table->text('created_at');
            $table->text('updated_at');
        });

        $db->connection($connection)->statement(
            'CREATE UNIQUE INDEX device_registry_user_device_idx ON device_registry (user_id, device_id)'
        );

        $schema->create('sync_encryption_state', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->integer('current_epoch')->nullable();
            $table->boolean('migration_in_progress')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
        });

        $db->connection($connection)->statement(
            'CREATE UNIQUE INDEX sync_encryption_state_user_idx ON sync_encryption_state (user_id)'
        );
    }

    /**
     * Mirror the production `relay_mailbox` schema onto $connection — the
     * ZK store `RelayMailbox` (driven by the real `RelayServeCommand`
     * handler) reads/writes when the fake HTTP factory swaps the default
     * connection to `relay`.
     */
    private function harnessMigrateRelaySchema(DatabaseManager $db, string $connection): void
    {
        $schema = $db->connection($connection)->getSchemaBuilder();

        $schema->create('relay_mailbox', function (Blueprint $table): void {
            $table->id();
            $table->string('sender_did');
            $table->string('recipient_did');
            $table->binary('blob');
            $table->text('created_at');
            $table->text('delivered_at')->nullable();
            $table->text('expires_at');
        });

        $db->connection($connection)->statement(
            'CREATE INDEX relay_mailbox_pending_idx ON relay_mailbox (recipient_did, delivered_at)'
            .' WHERE delivered_at IS NULL'
        );
    }
}
