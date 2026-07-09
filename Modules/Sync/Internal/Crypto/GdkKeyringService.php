<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Identity\SecureTempFile;
use RuntimeException;
use SodiumException;

/**
 * Generate/load/append/re-wrap the per-user GDK (Group Data Key) epoch
 * keyring (D-03/D-04) — mirrors `Modules\Sync\Internal\Identity\DeviceIdentityService`'s
 * encrypted-key-file idiom exactly: a single JSON blob (here, the list of
 * `{epoch_id, key_hex}` pairs) encrypted as a whole via `BackupEncryptor`
 * under the app-lock KEK released by `AppLockKeyService::release()`, staged
 * through the sanctioned 0700 `sync/gdk` directory (never `sys_get_temp_dir()`),
 * written atomically (stage plaintext at 0600 -> encrypt to a `.tmp` sibling
 * -> rename over the real path).
 *
 * D-02 weak-key-window guard (mirrors T-12-06): every keyring
 * read/write HARD-throws `\LogicException` when the KEK is null. The
 * keyring is never touched under anything weaker than the LOCK-04 key.
 *
 * D-04 append-only invariant (14-RESEARCH.md Pitfall 4): `appendEpoch()`
 * always re-persists EVERY prior epoch alongside the new one — the keyring
 * must never discard an epoch, because `OpLogRebuilder::rebuild()` can
 * replay op-log entries encrypted under any historical epoch at any time.
 *
 * T-14-01 invariant (mirrors `device_registry`'s STRIDE T-12-02 rule): NO
 * key material is ever written to `sync_encryption_state` — only the plain
 * integer `current_epoch` pointer. Secret key bytes live exclusively in the
 * encrypted keyring file on disk.
 */
final class GdkKeyringService
{
    public function __construct(
        private readonly AppLockKeyService $appLockKeyService,
        private readonly BackupEncryptor $backupEncryptor,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Generate GDK epoch 1, persist the encrypted keyring file, and set
     * `sync_encryption_state.current_epoch = 1`. Returns the in-memory
     * epoch DTO (carries the raw key hex — never persist the DTO itself).
     *
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws RuntimeException on a crypto / I-O failure.
     */
    public function generateAndPersist(int $userId, Session $session): GdkEpoch
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            // T-14-05 / weak-key-window guard: never write the keyring
            // without the LOCK-04 KEK.
            throw new \LogicException('Cannot generate GDK keyring: app-lock not unlocked.');
        }

        try {
            $rawKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
            $keyHex = sodium_bin2hex($rawKey);
            sodium_memzero($rawKey);

            $epoch = new GdkEpoch(epochId: 1, keyHex: $keyHex);
            $keyring = GdkKeyring::empty()->withEpoch($epoch);

            $this->writeKeyringFile($userId, $keyring, $kek);
            $this->setCurrentEpoch($userId, $epoch->epochId);

            return $epoch;
        } catch (SodiumException $e) {
            throw new RuntimeException('Failed to generate GDK keyring: sodium error.', 0, $e);
        } finally {
            sodium_memzero($kek);
        }
    }

    /**
     * D-10 atomicity fix: like {@see generateAndPersist()}, but STAGES the
     * keyring file (encrypts to the `.tmp` sibling of the real path)
     * WITHOUT renaming it into place, and returns a {@see GdkKeyringStage}
     * the caller must pass to {@see finalizeStagedEpoch()} (success) or
     * {@see discardStagedEpoch()} (rollback) once the surrounding SQL
     * transaction has committed/rolled back.
     *
     * The `sync_encryption_state.current_epoch` write still happens here,
     * via the same `DatabaseManager` connection the caller's ambient SQL
     * transaction runs on — so it participates in that transaction's
     * rollback exactly like before. Only the FILE finalize (the
     * rename-into-place) is deferred: a mid-migration failure that rolls
     * back `current_epoch` must never leave a finalized epoch-1 keyring
     * file contradicting it on disk (T-14.1-05).
     *
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws RuntimeException on a crypto / I-O failure.
     */
    public function stageFirstEpoch(int $userId, Session $session): GdkKeyringStage
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            // T-14-05 / weak-key-window guard: never write the keyring
            // without the LOCK-04 KEK.
            throw new \LogicException('Cannot generate GDK keyring: app-lock not unlocked.');
        }

        try {
            $rawKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
            $keyHex = sodium_bin2hex($rawKey);
            sodium_memzero($rawKey);

            $epoch = new GdkEpoch(epochId: 1, keyHex: $keyHex);
            $keyring = GdkKeyring::empty()->withEpoch($epoch);

            $tmpEncPath = $this->stageKeyringFile($userId, $keyring, $kek);
            $this->setCurrentEpoch($userId, $epoch->epochId);

            return new GdkKeyringStage($userId, $epoch, $tmpEncPath);
        } catch (SodiumException $e) {
            throw new RuntimeException('Failed to generate GDK keyring: sodium error.', 0, $e);
        } finally {
            sodium_memzero($kek);
        }
    }

    /**
     * D-10: finalize a previously-staged epoch — rename the `.tmp`
     * encrypted keyring file into place. Call ONLY after the ambient SQL
     * transaction that wrote `current_epoch` in {@see stageFirstEpoch()}
     * has committed.
     *
     * @throws RuntimeException if the rename fails.
     */
    public function finalizeStagedEpoch(GdkKeyringStage $stage): void
    {
        $encPath = $this->keyringPath($stage->userId);

        if (! @rename($stage->tmpEncPath, $encPath)) {
            @unlink($stage->tmpEncPath);

            throw new RuntimeException("Could not finalize the GDK keyring file for user {$stage->userId}.");
        }
    }

    /**
     * D-10: discard a previously-staged epoch — delete the un-finalized
     * `.tmp` encrypted keyring file after the ambient SQL transaction
     * rolled back. Best-effort cleanup (never throws): the SQL rollback is
     * the actual correctness guarantee, this only tidies up the orphaned
     * `.tmp` file.
     */
    public function discardStagedEpoch(GdkKeyringStage $stage): void
    {
        @unlink($stage->tmpEncPath);
    }

    /**
     * Decrypt and return the full keyring (all epochs). Empty keyring when
     * no file exists yet for $userId.
     *
     * @throws \LogicException when the app-lock KEK is unavailable.
     */
    public function loadKeyring(int $userId, Session $session): GdkKeyring
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            throw new \LogicException('Cannot load GDK keyring: app-lock not unlocked.');
        }

        try {
            return $this->readKeyringFile($userId, $kek);
        } finally {
            sodium_memzero($kek);
        }
    }

    /**
     * Append $epoch to the keyring WITHOUT discarding any prior epoch
     * (D-04 append-only forever), re-encrypt atomically, and advance
     * `sync_encryption_state.current_epoch` to $epoch->epochId.
     *
     * @throws \LogicException when the app-lock KEK is unavailable.
     */
    public function appendEpoch(int $userId, GdkEpoch $epoch, Session $session): void
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            throw new \LogicException('Cannot append GDK epoch: app-lock not unlocked.');
        }

        try {
            $keyring = $this->readKeyringFile($userId, $kek)->withEpoch($epoch);
            $this->writeKeyringFile($userId, $keyring, $kek);
            $this->setCurrentEpoch($userId, $epoch->epochId);
        } catch (SodiumException $e) {
            throw new RuntimeException('Failed to append GDK epoch: sodium error.', 0, $e);
        } finally {
            sodium_memzero($kek);
        }
    }

    /**
     * Resolve the epoch whose id equals `sync_encryption_state.current_epoch`,
     * paired with its raw key from the keyring — the single accessor the
     * write hooks (Plans 03/04) consume.
     *
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws RuntimeException when no current epoch is recorded, or the
     *                          keyring does not hold a key for it.
     */
    public function currentEpoch(int $userId, Session $session): GdkEpoch
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            throw new \LogicException('Cannot resolve current GDK epoch: app-lock not unlocked.');
        }

        try {
            $epochId = $this->currentEpochId($userId);
            if ($epochId === null) {
                throw new RuntimeException("No current GDK epoch recorded for user {$userId}.");
            }

            $keyHex = $this->readKeyringFile($userId, $kek)->keyFor($epochId);
            if ($keyHex === null) {
                throw new RuntimeException("GDK keyring for user {$userId} has no key for current epoch {$epochId}.");
            }

            return new GdkEpoch(epochId: $epochId, keyHex: $keyHex);
        } finally {
            sodium_memzero($kek);
        }
    }

    /**
     * Re-encrypt the SAME keyring contents (every epoch, unchanged) under
     * $newKek. $oldKek/$newKek are raw wrap-key bytes the caller already
     * holds directly (Plan 07's `AppLockProvisioner` derives both from the
     * old/new passphrase without going through `AppLockKeyService::release()`,
     * which only ever exposes ONE currently-active key) — this method takes
     * no `Session` for that reason. After this call the OLD key can no
     * longer decrypt the file; only $newKek can.
     *
     * @throws InvalidArgumentException when either key is empty.
     * @throws RuntimeException when $oldKek cannot decrypt the current file.
     */
    public function rewrapUnderNewKek(int $userId, string $oldKek, string $newKek): void
    {
        if ($oldKek === '' || $newKek === '') {
            throw new InvalidArgumentException('GdkKeyringService::rewrapUnderNewKek — oldKek/newKek must not be empty.');
        }

        try {
            $keyring = $this->readKeyringFile($userId, $oldKek);
            $this->writeKeyringFile($userId, $keyring, $newKek);
        } catch (SodiumException $e) {
            throw new RuntimeException('Failed to re-wrap GDK keyring: sodium error.', 0, $e);
        } finally {
            sodium_memzero($oldKek);
            sodium_memzero($newKek);
        }
    }

    private function keyringPath(int $userId): string
    {
        return UserDataPathService::appPath("sync/gdk/{$userId}.enc");
    }

    /**
     * @throws RuntimeException when $kek cannot decrypt the file (wrong
     *                          key, tampering, or a truncated/corrupt file).
     */
    private function readKeyringFile(int $userId, string $kek): GdkKeyring
    {
        $encPath = $this->keyringPath($userId);
        if (! file_exists($encPath)) {
            return GdkKeyring::empty();
        }

        $dir = dirname($encPath);
        @mkdir($dir, 0700, true);

        // Stage the decrypted plaintext inside the sanctioned 0700 gdk
        // directory — never sys_get_temp_dir() — and lock it to 0600
        // immediately after BackupEncryptor::decrypt() writes it (its
        // file-path API has no permission handling of its own).
        $tmpPath = $dir.DIRECTORY_SEPARATOR.'beatrax_gdk_'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $this->backupEncryptor->decrypt($encPath, $tmpPath, $kek);
            SecureTempFile::lockDown($tmpPath);

            $payload = json_decode((string) file_get_contents($tmpPath), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new RuntimeException("Corrupt GDK keyring payload for user {$userId}.");
            }

            /** @var array<string, mixed> $payload */
            return GdkKeyring::fromArray($payload);
        } finally {
            @unlink($tmpPath);
        }
    }

    private function writeKeyringFile(int $userId, GdkKeyring $keyring, string $kek): void
    {
        $encPath = $this->keyringPath($userId);
        $tmpEncPath = $this->stageKeyringFile($userId, $keyring, $kek);

        // Rename over the real path immediately — every OTHER caller of
        // this method (generateAndPersist/appendEpoch/rewrapUnderNewKek)
        // does not need the D-10 deferred-finalize seam: none of them run
        // inside an ambient SQL transaction whose rollback could strand
        // this file.
        if (! @rename($tmpEncPath, $encPath)) {
            @unlink($tmpEncPath);

            throw new RuntimeException("Could not finalize the GDK keyring file for user {$userId}.");
        }
    }

    /**
     * Encrypt $keyring to a `.tmp` sibling of the real keyring path WITHOUT
     * renaming it into place — the shared staging primitive behind both
     * {@see writeKeyringFile()} (immediate finalize) and
     * {@see stageFirstEpoch()} (deferred finalize, D-10). Returns the
     * `.tmp` path.
     */
    private function stageKeyringFile(int $userId, GdkKeyring $keyring, string $kek): string
    {
        $encPath = $this->keyringPath($userId);
        $dir = dirname($encPath);
        @mkdir($dir, 0700, true);

        $payload = json_encode($keyring->toArray(), JSON_THROW_ON_ERROR);

        // Stage the plaintext keyring JSON inside the 0700 gdk directory —
        // never sys_get_temp_dir() — locked to 0600 immediately
        // (SecureTempFile::write throws if the chmod fails).
        $tmpPlainPath = $dir.DIRECTORY_SEPARATOR.'beatrax_gdk_'.bin2hex(random_bytes(8)).'.tmp';
        SecureTempFile::write($tmpPlainPath, $payload);

        // Encrypt to a `.enc.tmp` sibling; the caller decides when (or
        // whether) to rename it over the real path.
        $tmpEncPath = $encPath.'.tmp';

        try {
            $this->backupEncryptor->encrypt($tmpPlainPath, $tmpEncPath, $kek);
        } finally {
            @unlink($tmpPlainPath);
        }

        return $tmpEncPath;
    }

    /**
     * Upsert `sync_encryption_state.current_epoch` — NEVER any key material
     * (T-14-01). Sets `enabled_at` only the first time a row is created for
     * this user (i.e. when encryption is first enabled).
     */
    private function setCurrentEpoch(int $userId, int $epochId): void
    {
        $connection = $this->db->connection();
        $now = $this->clock->now();

        $existing = $connection->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->first(['id']);

        if ($existing === null) {
            $connection->table('sync_encryption_state')->insert([
                'user_id' => $userId,
                'current_epoch' => $epochId,
                'migration_in_progress' => false,
                'enabled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $connection->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->update([
                'current_epoch' => $epochId,
                'updated_at' => $now,
            ]);
    }

    private function currentEpochId(int $userId): ?int
    {
        $row = $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->first(['current_epoch']);

        if ($row === null) {
            return null;
        }

        /** @var mixed $value */
        $value = $row->current_epoch;

        return is_numeric($value) ? (int) $value : null;
    }
}
