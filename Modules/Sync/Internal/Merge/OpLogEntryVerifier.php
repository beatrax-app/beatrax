<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkKeyring;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

final readonly class OpLogEntryVerifier
{
    /**
     * @param  array<string, string>  $deviceKeys  device-id => hex Ed25519 public key.
     */
    public function __construct(
        private DatabaseManager $db,
        private MergeRulesRegistry $rules,
        private RegisteredColumns $columns,
        private SensitiveFieldRegistry $sensitiveFields,
        private array $deviceKeys,
        private DeviceKeySigner $signer,
        private ?OpLogFieldCrypto $fieldCrypto,
        private ?GdkKeyringService $keyringService,
        private ?Session $session,
        private OpLogQuarantine $quarantine,
        private PriorAuthorship $priorAuthorship,
    ) {}

    // Filters to the scoped $userId (defense in depth) and the Ed25519 gate,
    // durably persisting every accepted entry BEFORE any decrypt. The GDK
    // keyring is loaded at most once per call, lazily, and kept local — never
    // instance state, since this readonly verifier is reused across calls.
    /**
     * @param  list<OpLogEntry>  $entries
     * @return list<OpLogEntry>
     */
    public function verifyPersistAndPrepare(array $entries, int $userId, string $now): array
    {
        $verified = [];
        $keyring = null;
        $keyringLoaded = false;

        foreach ($entries as $wireEntry) {
            $entry = $this->authorizeAndPersist($wireEntry, $userId, $now);

            if ($entry === null) {
                continue;
            }

            if ($entry->gdkEpoch === null || ! $this->sensitiveFields->isSensitive($entry->table, $entry->field)) {
                $verified[] = $entry;

                continue;
            }

            if (! $keyringLoaded) {
                $keyring = $this->tryLoadKeyring($userId);
                $keyringLoaded = true;
            }

            $decrypted = $this->decryptForStrategy($entry, $keyring, $now);

            if ($decrypted !== null) {
                $verified[] = $decrypted;
            }
        }

        return $verified;
    }

    /**
     * @param  list<OpLogEntry>  $stored  Read back OUT of op_log_entries, so already gated.
     * @return list<OpLogEntry>
     *
     * @link ../../../../.docs/features/sync/op-log-merge-rules.md#a-batch-is-not-the-set-a-strategy-resolves-over
     */
    public function prepareStored(array $stored, int $userId): array
    {
        $prepared = [];
        $keyring = null;
        $keyringLoaded = false;

        foreach ($stored as $entry) {
            if ($entry->gdkEpoch === null || ! $this->sensitiveFields->isSensitive($entry->table, $entry->field)) {
                $prepared[] = $entry;

                continue;
            }

            if (! $keyringLoaded) {
                $keyring = $this->tryLoadKeyring($userId);
                $keyringLoaded = true;
            }

            $plain = $this->decryptEntryValue($entry, $keyring);

            if ($plain !== null) {
                $prepared[] = $entry->withDecryptedValue($plain);
            }
        }

        return $prepared;
    }

    // A rejected entry goes to quarantine ONLY, never to op_log_entries, and
    // a passing one is persisted BEFORE any decrypt so the durable log keeps
    // the original ciphertext. Authorization judges the entry as the peer sent
    // it; the copy returned is re-scoped onto this device's own user.
    /**
     * @return OpLogEntry|null The accepted, locally-scoped entry, or null when rejected.
     */
    private function authorizeAndPersist(OpLogEntry $entry, int $userId, string $now): ?OpLogEntry
    {
        $reason = $this->rejectionReason($entry, $userId);

        if ($reason !== null) {
            $this->quarantine->record($entry, $reason, $now);

            return null;
        }

        $entry = $entry->withUserId($userId);

        $this->persistVerifiedEntry($entry, $now);

        return $entry;
    }

    // Membership is proven by the DEVICE: $deviceKeys is confirmed-only and
    // user-scoped, so the entry's user_id — a per-device autoincrement whose
    // comparison rejected every op a paired peer sent — is not checked. System
    // cascade ops are local, bypass the Ed25519 gate, and keep that check.
    private function rejectionReason(OpLogEntry $entry, int $userId): ?QuarantineReason
    {
        $reason = match (true) {
            ! $this->rules->isRegistered($entry->table) => QuarantineReason::UnknownTable,
            $this->isSystemDevice($entry->deviceId) => $entry->userId === $userId
                ? null
                : QuarantineReason::CrossUser,
            $this->publicKeyFor($entry, $userId) === null => $this->unverifiableAuthor($entry, $userId),
            ! $this->verifySignature($entry, $userId) => QuarantineReason::ForgedSignature,
            default => null,
        };

        if ($reason !== null) {
            return $reason;
        }

        // Only an entry past the table + cross-user + Ed25519 gates reaches the
        // column gate: a SET/CREATE naming a field that is not a real column of
        // its table quarantines here instead of failing at the DB write. The
        // DeleteTombstone sentinel never names a column, so it is exempt.
        return $this->hasRegisteredColumn($entry) ? null : QuarantineReason::UnknownColumn;
    }

    private function hasRegisteredColumn(OpLogEntry $entry): bool
    {
        return $entry->opType === OpType::DeleteTombstone
            || $this->columns->isRegistered($entry->table, $entry->field);
    }

    // Whether a device id belongs to a deterministically re-derived system op
    // (e.g. the pair-link cascade) that legitimately bypasses the Ed25519
    // signature gate. Produced ONLY by the replayer itself and reproduced
    // identically on rebuild, so trusted by construction.
    private function isSystemDevice(string $deviceId): bool
    {
        return $deviceId === OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID;
    }

    // The confirmed map first, then the key the registry retains for a device
    // that has since been removed. Retention adds no admission: removal closes
    // the Noise transport, so a revoked device can no longer deliver anything,
    // and the ops it wrote while trusted are ordinary history.
    private function publicKeyFor(OpLogEntry $entry, int $userId): ?string
    {
        return $this->deviceKeys[$entry->deviceId]
            ?? $this->priorAuthorship->retainedKeyFor($userId, $entry->deviceId);
    }

    // An entry this device already accepted stays accepted even when nothing
    // left can verify it — a device removed before the registry retained its
    // key leaves exactly that state, and a rebuild that refused it would drop
    // every row it created and never put one back.
    private function unverifiableAuthor(OpLogEntry $entry, int $userId): ?QuarantineReason
    {
        return $this->priorAuthorship->alreadyAccepted($entry, $userId)
            ? null
            : QuarantineReason::MissingDeviceKey;
    }

    private function verifySignature(OpLogEntry $entry, int $userId): bool
    {
        $pubKeyHex = $this->publicKeyFor($entry, $userId);

        if ($pubKeyHex === null) {
            return false;
        }

        return $this->signer->verifyAny($entry->signatureCandidates(), $entry->signature, sodium_hex2bin($pubKeyHex));
    }

    // Persists a VERIFIED entry to the authoritative op_log_entries table
    // (upsert-by-identity, so replay is idempotent). Called ONLY after an
    // entry passes the cross-user + Ed25519 gate (or is an allow-listed
    // system op), so the durable log never holds forged or cross-user rows.
    private function persistVerifiedEntry(OpLogEntry $entry, string $now): void
    {
        $this->db->connection()->table('op_log_entries')->updateOrInsert(
            [
                'user_id' => $entry->userId,
                'device_id' => $entry->deviceId,
                'table_name' => $entry->table,
                'pk' => (string) $entry->pk,
                'field' => $entry->field,
                'hlc_l' => $entry->hlcL,
                'hlc_c' => $entry->hlcC,
            ],
            [
                'op_type' => $entry->opType->value,
                'value' => $entry->value,
                'gdk_epoch' => $entry->gdkEpoch,
                'signature' => $entry->signature,
                // Without this the re-scope above is one-way: a v1 signature
                // covers user_id, so the entry could never be re-verified.
                'origin_user_id' => $entry->originUserId,
                'recorded_at' => $now,
            ],
        );
    }

    // Decrypts a GDK-tagged sensitive entry's value for strategy resolution.
    // Returns a NEW OpLogEntry carrying the decrypted plaintext (->gdkEpoch
    // unchanged, needed so a later write-back knows the source was
    // encrypted). Returns null (quarantined) on any decrypt failure.
    private function decryptForStrategy(OpLogEntry $entry, ?GdkKeyring $keyring, string $now): ?OpLogEntry
    {
        if ($entry->value === null || $entry->gdkEpoch === null) {
            return $entry;
        }

        $plain = $this->decryptEntryValue($entry, $keyring);

        if ($plain === null) {
            $this->quarantine->record($entry, QuarantineReason::GdkDecryptFailed, $now);

            return null;
        }

        return $entry->withDecryptedValue($plain);
    }

    // Returns the decrypted plaintext, or null for ANY fail-closed condition
    // (crypto/keyring unavailable, missing epoch key, or AEAD failure) — the
    // caller quarantines uniformly on null. The raw key is zeroed even when
    // decrypt throws.
    private function decryptEntryValue(OpLogEntry $entry, ?GdkKeyring $keyring): ?string
    {
        if ($this->fieldCrypto === null || $keyring === null || $entry->gdkEpoch === null || $entry->value === null) {
            return null;
        }

        $keyHex = $keyring->keyFor($entry->gdkEpoch);

        if ($keyHex === null) {
            return null;
        }

        $rawKey = sodium_hex2bin($keyHex);
        try {
            $plain = $this->fieldCrypto->decrypt(
                $entry->value,
                $rawKey,
                SensitiveColumnCodec::opLogAssociatedData($entry->table, $entry->pk, $entry->field, $entry->gdkEpoch),
            );
        } finally {
            sodium_memzero($rawKey);
        }

        return $plain === false ? null : $plain;
    }

    // Loads $userId's GDK keyring, or null when it cannot currently be loaded
    // (crypto services unavailable, no session, app locked, or GDK never
    // enabled). Never throws — callers treat null as "cannot decrypt now".
    private function tryLoadKeyring(int $userId): ?GdkKeyring
    {
        if ($this->keyringService === null || $this->session === null) {
            return null;
        }

        try {
            $keyring = $this->keyringService->loadKeyring($userId, $this->session);
        } catch (\LogicException) {
            return null;
        }

        return $keyring->epochs() === [] ? null : $keyring;
    }
}
