<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\Sync\Public\Services\DeviceRegistryService;

// A key a confirmed device relayed for a device this household can no longer
// pair with, because the other end of that ceremony is gone. It is stored
// unconfirmed, it names who vouched for it, and until the reader confirms it it
// verifies nothing. Confirmed, it verifies OP SIGNATURES and nothing else.
/**
 * @link ../../../../.docs/features/sync/introducing-a-device-nobody-can-pair-with.md
 */
final readonly class DeviceIntroductionService
{
    public function __construct(
        private DatabaseManager $db,
        private DeviceRegistryService $registry,
        private Clock $clock,
    ) {}

    // Records what a peer offered, leaving every row unconfirmed. Re-running is
    // how the withheld count stays current, so an existing row keeps its
    // confirmation and its identity and takes only the new count and voucher.
    /**
     * @param  list<array{device_id: string, name: string, ed25519_public_key_hex: string}>  $offered
     * @param  array<string, int>  $withheld  author device id => entries the peer is holding back.
     * @return int How many introductions were stored or refreshed.
     */
    public function record(int $userId, string $introducedBy, array $offered, array $withheld): int
    {
        if ($introducedBy === '') {
            return 0;
        }

        $selfKeyHex = $this->selfEd25519KeyHex($userId);

        if ($selfKeyHex === null) {
            return 0;
        }

        $paired = $this->registry->retainedDeviceKeys($userId);
        $now = Instant::zulu($this->clock->now());
        $stored = 0;

        foreach ($offered as $introduction) {
            // A device the registry already knows is answered by pairing, in
            // either direction: confirmed, it needs nothing; revoked, the reader
            // took that decision and an introduction must not reverse it.
            if (isset($paired[$introduction['device_id']]) || $introduction['device_id'] === '') {
                continue;
            }

            if ($this->store($userId, $introducedBy, $introduction, $selfKeyHex, $withheld, $now)) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * @param  array{device_id: string, name: string, ed25519_public_key_hex: string}  $introduction
     * @param  array<string, int>  $withheld
     */
    private function store(
        int $userId,
        string $introducedBy,
        array $introduction,
        string $selfKeyHex,
        array $withheld,
        string $now,
    ): bool {
        $deviceId = $introduction['device_id'];

        try {
            $words = implode(' ', new SafetyNumberDeriver(Bip39WordList::WORDS)->deriveWords(
                $selfKeyHex,
                $introduction['ed25519_public_key_hex'],
            ));
        } catch (InvalidPublicKeyException) {
            return false;
        }

        $connection = $this->db->connection();
        $existing = $connection->table('device_introductions')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->first();

        $refreshed = [
            'name' => $introduction['name'],
            'introduced_by_device_id' => $introducedBy,
            'withheld_entry_count' => max(0, $withheld[$deviceId] ?? 0),
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            // The key is left alone on purpose. Rewriting it would let a second
            // exchange swap the identity under a confirmation the reader has
            // already given, which is the whole of what E2-R18 is guarding.
            $connection->table('device_introductions')
                ->where('user_id', $userId)
                ->where('device_id', $deviceId)
                ->update($refreshed);

            return true;
        }

        $connection->table('device_introductions')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'ed25519_public_key_hex' => $introduction['ed25519_public_key_hex'],
            'safety_number_words' => $words,
            'introduced_at' => $now,
            'verification_confirmed_at' => null,
            'created_at' => $now,
            ...$refreshed,
        ]);

        return true;
    }

    /**
     * @return array<int, \stdClass>
     */
    public function forUser(int $userId): array
    {
        return $this->db->connection()
            ->table('device_introductions')
            ->where('user_id', $userId)
            ->orderBy('introduced_at')
            ->get()
            ->all();
    }

    // The reader's act, and the only thing that makes a relayed key verify
    // anything. Idempotent, and scoped to the user so one household's decision
    // cannot be made from another's session.
    public function confirm(int $userId, int $introductionId): bool
    {
        $now = Instant::zulu($this->clock->now());

        return $this->db->connection()
            ->table('device_introductions')
            ->where('id', $introductionId)
            ->where('user_id', $userId)
            ->whereNull('verification_confirmed_at')
            ->update(['verification_confirmed_at' => $now, 'updated_at' => $now]) > 0;
    }

    // Removal, for a confirmed introduction and an offered one alike. No epoch
    // rotation follows, because an introduced device was never given an epoch:
    // there is no key material to take back.
    public function forget(int $userId, int $introductionId): void
    {
        $this->db->connection()
            ->table('device_introductions')
            ->where('id', $introductionId)
            ->where('user_id', $userId)
            ->delete();
    }

    private function selfEd25519KeyHex(int $userId): ?string
    {
        $keyHex = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('ed25519_public_key_hex');

        return is_string($keyHex) && $keyHex !== '' ? $keyHex : null;
    }
}
