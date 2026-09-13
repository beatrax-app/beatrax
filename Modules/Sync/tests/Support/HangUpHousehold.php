<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Services\DeviceRegistryService;

// The same household as ResponderHousehold, for the cases that end a session
// early: it hands back the peer's Ed25519 secret so a case can sign history
// with it, and reads the session row back rather than restating the query.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md
 */
final class HangUpHousehold
{
    public readonly int $userId;

    public readonly string $secretKey;

    public readonly string $publicKey;

    public function __construct(public readonly string $deviceId = 'desktop-self')
    {
        $user = User::query()->create([
            'username' => 'hangup-'.bin2hex(random_bytes(5)),
            'password' => bcrypt('fixture'),
            'period_start_day' => 1,
            'default_currency_view' => 'eur_only',
        ]);

        $this->userId = (int) $user->id;

        $keypair = sodium_crypto_kx_keypair();
        $this->secretKey = sodium_crypto_kx_secretkey($keypair);
        $this->publicKey = sodium_crypto_kx_publickey($keypair);

        $this->register($this->deviceId, sodium_bin2hex($this->publicKey), isSelf: true);
    }

    public function register(string $deviceId, string $x25519Hex, bool $isSelf = false, ?string $confirmedAt = '2026-09-01T10:05:00Z'): string
    {
        $signing = sodium_crypto_sign_keypair();

        $this->db()->connection()->table('device_registry')->insert([
            'user_id' => $this->userId,
            'device_id' => $deviceId,
            'name' => $deviceId,
            'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($signing)),
            'x25519_public_key_hex' => $x25519Hex,
            'safety_number_words' => 'abandon ability able about above absent',
            'is_self' => $isSelf ? 1 : 0,
            'paired_at' => '2026-09-01T10:00:00Z',
            'confirmed_at' => $confirmedAt,
            'last_seen_at' => null,
            'created_at' => '2026-09-01T10:00:00Z',
            'updated_at' => '2026-09-01T10:00:00Z',
        ]);

        return sodium_bin2hex(sodium_crypto_sign_secretkey($signing));
    }

    public function handler(RecordingLogger $logger): SyncWebSocketHandler
    {
        return new SyncWebSocketHandler(
            registryService: app(DeviceRegistryService::class),
            signer: new DeviceKeySigner,
            framer: new TransportFramer,
            catchUp: app(PeerCatchUpExchanger::class),
            db: $this->db(),
            clock: app(Clock::class),
            logger: $logger,
            localStaticSecret: $this->secretKey,
            localStaticPublic: $this->publicKey,
            localDeviceId: $this->deviceId,
            userId: $this->userId,
        );
    }

    public function sessionRow(): ?object
    {
        return $this->db()->connection()->table('sync_sessions')->where('user_id', $this->userId)->first();
    }

    public function db(): DatabaseManager
    {
        return app(DatabaseManager::class);
    }
}
