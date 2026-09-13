<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Services\DeviceRegistryService;

// One user, one self device and whatever peers a case registers, plus the
// responder built over them. Four cases need the same household before they can
// say anything, and a helper declared in any one of them exists only in the
// worker that loaded that file.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md
 */
final class ResponderHousehold
{
    public readonly int $userId;

    public readonly string $secretKey;

    public readonly string $publicKey;

    public readonly string $signingSecretHex;

    public function __construct(public readonly string $deviceId = 'desktop-self')
    {
        $user = User::query()->create([
            'username' => 'responder-'.bin2hex(random_bytes(5)),
            'password' => bcrypt('fixture'),
            'period_start_day' => 1,
            'default_currency_view' => 'eur_only',
        ]);

        $this->userId = (int) $user->id;

        $keypair = sodium_crypto_kx_keypair();
        $this->secretKey = sodium_crypto_kx_secretkey($keypair);
        $this->publicKey = sodium_crypto_kx_publickey($keypair);

        $signing = sodium_crypto_sign_keypair();
        $this->signingSecretHex = sodium_bin2hex(sodium_crypto_sign_secretkey($signing));

        $this->register($this->deviceId, sodium_bin2hex($this->publicKey), sodium_bin2hex(sodium_crypto_sign_publickey($signing)), isSelf: true);
    }

    public function register(string $deviceId, string $x25519Hex, string $ed25519Hex, bool $isSelf = false, ?string $confirmedAt = '2026-09-01T10:05:00Z'): void
    {
        $this->db()->connection()->table('device_registry')->insert([
            'user_id' => $this->userId,
            'device_id' => $deviceId,
            'name' => $deviceId,
            'ed25519_public_key_hex' => $ed25519Hex,
            'x25519_public_key_hex' => $x25519Hex,
            'safety_number_words' => 'abandon ability able about above absent',
            'is_self' => $isSelf ? 1 : 0,
            'paired_at' => '2026-09-01T10:00:00Z',
            'confirmed_at' => $confirmedAt,
            'last_seen_at' => null,
            'created_at' => '2026-09-01T10:00:00Z',
            'updated_at' => '2026-09-01T10:00:00Z',
        ]);
    }

    public function owe(string $author, int $hlcL, string $pk): void
    {
        $this->db()->connection()->table('op_log_entries')->insert([
            'user_id' => $this->userId,
            'device_id' => $author,
            'table_name' => 'merchants',
            'pk' => $pk,
            'field' => 'name',
            'op_type' => OpType::Set->value,
            'value' => json_encode('Owed '.$pk, JSON_THROW_ON_ERROR),
            'hlc_l' => $hlcL,
            'hlc_c' => 0,
            'signature' => str_repeat('a', 128),
            'recorded_at' => '2026-09-01T10:00:00Z',
        ]);
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

    public function db(): DatabaseManager
    {
        return app(DatabaseManager::class);
    }
}
