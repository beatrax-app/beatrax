<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Amp\Http\Server\Driver\Client as AmpClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Socket\InternetAddress;
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
use Modules\Sync\Internal\Transport\Relay\RelayDrainRegistry;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\Relay\RelayRateLimiter;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;
use Psr\Log\NullLogger;
use ReflectionMethod;

use function Amp\ByteStream\buffer;

/**
 * @link ../../../../.docs/features/sync/cross-device-pairing-test-harness.md
 */
trait CrossDevicePairingHarness
{
    private RelayConfig $harnessRelayConfig;

    // Call once per test, before any device-role code runs.
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

        // Each device carries its own local relay_mailbox outbox, a different thing
        // from the `relay` connection's transport mailbox; without these two copies
        // the GDK epoch fan-out tests fail on a missing table.
        $this->harnessMigrateRelaySchema($db, 'desktop');
        $this->harnessMigrateRelaySchema($db, 'phone');

        $this->harnessRelayConfig = new RelayConfig;
        $this->harnessRelayConfig->setEndpointUrl('https://relay.test');
        $this->harnessRelayConfig->setAuthToken('cross-device-harness-relay-secret');

        $mailbox = new RelayMailbox($db, $this->app->make(Clock::class));
        // In-process over a fake HTTP factory: no socket, so the TLS material is inert.
        $command = new RelayServeCommand(
            new NullLogger,
            $mailbox,
            new RelayDrainRegistry,
            new RelayRateLimiter($this->app->make(Clock::class)),
            new RelayTlsMaterial,
            new DaemonShutdownSignal,
        );

        $relayClient = new RelayClient(
            $this->harnessRelayHttpFactory($command, $db),
            $this->harnessRelayConfig,
            new NullLogger,
        );

        // Rebound as an instance so Livewire components and PairingFrameCourier
        // resolve the fake-transport client rather than opening a real connection.
        $this->app->instance(RelayClient::class, $relayClient);
    }

    // Runs $fn with the default connection swapped to 'desktop' or 'phone', and
    // restores the previous default even when $fn throws.
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

    // Call from the consuming test file's afterEach(): these artifacts are on disk,
    // so without this a later test starts with a relay already configured.
    protected function crossDevicePairingTearDown(): void
    {
        $secretsDir = UserDataPathService::secretsPath();
        $tokenPath = $secretsDir.DIRECTORY_SEPARATOR.'sync-relay-token.json';
        $drainSecretPath = $secretsDir.DIRECTORY_SEPARATOR.'sync-relay-drain-secret.json';
        $drainRegistryPath = $secretsDir.DIRECTORY_SEPARATOR.'sync-relay-drain-registry.json';
        $relayPath = UserDataPathService::appPath('sync/relay.json');

        foreach ([$tokenPath, $drainSecretPath, $drainRegistryPath, $relayPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    // Routes requests through the real RelayServeCommand handler, with the default
    // connection swapped to `relay` so RelayMailbox writes land on the relay store
    // rather than whichever device connection was active at the call site.
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
                // Deliver reads the source IP for its rate-limit bucket; no socket here.
                $ampClient->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress('127.0.0.1', 12345));
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

    // Mirrors the production schema, indexes included, onto a genuinely separate
    // SQLite database from the app's own default test connection.
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
            $table->string('responder_name')->nullable();
            $table->string('state')->default('pending');
            $table->text('expires_at');
            $table->text('accepted_at')->nullable();
            $table->text('initiator_confirmed_at')->nullable();
            $table->text('responder_confirmed_at')->nullable();
            $table->text('initiator_seeded_at')->nullable();
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
            $table->timestamp('counterparty_key_backfilled_at')->nullable();
            $table->timestamps();
        });

        $db->connection($connection)->statement(
            'CREATE UNIQUE INDEX sync_encryption_state_user_idx ON sync_encryption_state (user_id)'
        );

        // The fan-out asks whether this device already holds rows keyed under
        // its blind-index key, because the answer decides which side gives way
        // when two devices hold different ones. Only the column that question
        // reads is mirrored; nothing here exercises the ledger itself.
        $schema->create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('counterparty_normalized', 80);
        });
    }

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
