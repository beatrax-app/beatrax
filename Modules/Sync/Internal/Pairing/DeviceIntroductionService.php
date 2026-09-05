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

    // Records what a peer offered, leaving every row unconfirmed. Re-running it
    // refreshes the voucher and the name, so an existing row keeps the identity
    // a reader may already have confirmed. What the peer is holding back is
    // WithheldLedger's, because it arrives for authors nobody offered a key for.
    /**
     * @param  list<array{device_id: string, name: string, ed25519_public_key_hex: string}>  $offered
     * @return int How many introductions were stored or refreshed.
     */
    public function record(int $userId, string $introducedBy, array $offered): int
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

            if ($this->store($userId, $introducedBy, $introduction, $selfKeyHex, $now)) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * @param  array{device_id: string, name: string, ed25519_public_key_hex: string}  $introduction
     */
    private function store(
        int $userId,
        string $introducedBy,
        array $introduction,
        string $selfKeyHex,
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
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            // The key is left alone on purpose. Rewriting it would let a second
            // exchange swap the identity out from under a confirmation the
            // reader has already given, which is the whole of the guard.
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

    // Shadowed rows are left out on the same test record() refuses them on, so
    // the list cannot offer an act on a device the device list already answers
    // for: paired, it needs nothing; removed, saying "confirmed for signatures"
    // would describe a grant signatureVerificationKeys() no longer makes.
    /**
     * @return array<int, \stdClass>
     */
    public function forUser(int $userId): array
    {
        $paired = $this->registry->retainedDeviceKeys($userId);

        $rows = $this->db->connection()
            ->table('device_introductions')
            ->where('user_id', $userId)
            ->orderBy('introduced_at')
            ->get()
            ->all();

        return array_values(array_filter(
            $rows,
            static fn (\stdClass $row): bool => ! is_string($row->device_id) || ! isset($paired[$row->device_id]),
        ));
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
