<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupDecryptionException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\Concerns\ManagesBlindIndexKey;
use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Exceptions\KeyringStateException;
use Modules\Sync\Internal\Exceptions\SecretFileException;
use Modules\Sync\Internal\Identity\SecureTempFile;
use SodiumException;

final class GdkKeyringService
{
    use ManagesBlindIndexKey;

    // Wide enough that two distinct KEKs cannot collide onto one cache entry;
    // this is a lookup key, not a security boundary.
    private const CACHE_FINGERPRINT_BYTES = 16;

    // Above this, the keyring file was written with password-hardening cost
    // and is worth re-writing once. Deliberately a threshold rather than an
    // equality check on the current setting, so tuning the write cost never
    // turns into a re-write loop.
    private const CHEAP_READ_MEMLIMIT = 1048576;

    // Decrypted keyrings for this process, keyed by user + KEK fingerprint
    // so a withheld or rotated key can never resolve to a cached entry.
    /** @var array<string, GdkKeyring> */
    private array $keyringCache = [];

    // Every keyring read/write HARD-throws \LogicException when the KEK is
    // null — the keyring is never touched under anything weaker than the
    // app-lock key. NO key material is ever written to
    // sync_encryption_state — only the plain integer current_epoch pointer.
    public function __construct(
        private readonly AppLockKeyService $appLockKeyService,
        private readonly FileEncryptor $backupEncryptor,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SodiumPrimitives $sodium,
    ) {}

    // Mints the first GDK epoch, persists the encrypted keyring file, and
    // points sync_encryption_state.current_epoch at it. The id is random, not
    // 1: two devices that each generated a first epoch both called it 1 over
    // different keys. Returns the in-memory DTO — never persist the DTO.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws CryptoOperationFailedException on a libsodium failure.
     * @throws SecretFileException when the keyring file cannot be written.
     */
    public function generateAndPersist(int $userId, Session $session): GdkEpoch
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            throw new \LogicException('Cannot generate GDK keyring: app-lock not unlocked.');
        }

        try {
            $rawKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
            $keyHex = $this->sodium->binToHex($rawKey);
            sodium_memzero($rawKey);

            $epoch = new GdkEpoch(epochId: GdkEpochId::mint([]), keyHex: $keyHex);
            $keyring = GdkKeyring::empty()
                ->withEpoch($epoch)
                ->withBlindIndexKey($this->mintBlindIndexKeyHex());

            $this->writeKeyringFile($userId, $keyring, $kek);
            $this->setCurrentEpoch($userId, $epoch->epochId);

            return $epoch;
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('GDK keyring generation', $e);
        } finally {
            sodium_memzero($kek);
        }
    }

    // Like generateAndPersist(), but STAGES the keyring file (encrypts to the
    // .tmp sibling of the real path) WITHOUT renaming it into place, and
    // returns a GdkKeyringStage the caller must pass to finalizeStagedEpoch()
    // or discardStagedEpoch() once the surrounding SQL transaction resolves.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws CryptoOperationFailedException on a libsodium failure.
     * @throws SecretFileException when the keyring file cannot be staged.
     */
    public function stageFirstEpoch(int $userId, Session $session): GdkKeyringStage
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            throw new \LogicException('Cannot generate GDK keyring: app-lock not unlocked.');
        }

        try {
            $rawKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
            $keyHex = $this->sodium->binToHex($rawKey);
            sodium_memzero($rawKey);

            $blindIndexKeyHex = $this->mintBlindIndexKeyHex();
            $epoch = new GdkEpoch(epochId: GdkEpochId::mint([]), keyHex: $keyHex);
            $keyring = GdkKeyring::empty()
                ->withEpoch($epoch)
                ->withBlindIndexKey($blindIndexKeyHex);

            $tmpEncPath = $this->stageKeyringFile($userId, $keyring, $kek);
            $this->setCurrentEpoch($userId, $epoch->epochId);

            return new GdkKeyringStage($userId, $epoch, $tmpEncPath, $blindIndexKeyHex);
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('GDK keyring generation', $e);
        } finally {
            sodium_memzero($kek);
        }
    }

    // Finalizes a previously-staged epoch — renames the .tmp encrypted
    // keyring file into place. Call ONLY after the ambient SQL transaction
    // that wrote current_epoch in stageFirstEpoch() has committed.
    /**
     * @throws SecretFileException if the rename fails.
     */
    public function finalizeStagedEpoch(GdkKeyringStage $stage): void
    {
        $encPath = $this->keyringPath($stage->userId);

        // The same reason writeKeyringFile() drops it, arriving by the other
        // door. Anything that read the keyring while this epoch was still
        // staged memoised an EMPTY one — the real file did not exist yet — and
        // that answer outlived the rename that made it wrong.
        $this->keyringCache = [];

        if (! @rename($stage->tmpEncPath, $encPath)) {
            // Do NOT @unlink the staged file on rename failure. At this point
            // current_epoch is already committed and this .tmp is the ONLY
            // copy of the epoch key — keep it so a re-entrant call can retry.
            throw SecretFileException::couldNotFinalizeKeyring($stage->userId);
        }
    }

    // Deletes the un-finalized .tmp encrypted keyring file after the ambient
    // SQL transaction rolled back. Best-effort cleanup (never throws): the
    // SQL rollback is the actual correctness guarantee, this only tidies up
    // the orphaned .tmp file.
    public function discardStagedEpoch(GdkKeyringStage $stage): void
    {
        @unlink($stage->tmpEncPath);
    }

    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws BackupDecryptionException when the held KEK does not open the file.
     */
    public function loadKeyring(int $userId, Session $session): GdkKeyring
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            throw new \LogicException('Cannot load GDK keyring: app-lock not unlocked.');
        }

        try {
            // SensitiveColumnCodec calls this once per decrypted VALUE, so a
            // 164-row page paid 164 key derivations — minutes in libsodium,
            // blowing max_execution_time and wedging the single-threaded
            // desktop server for every other request.
            $fingerprint = $this->keyringCacheKey($userId, $kek);

            if (isset($this->keyringCache[$fingerprint])) {
                return $this->keyringCache[$fingerprint];
            }

            $keyring = $this->readKeyringFile($userId, $kek);
            $this->rewriteIfCostlyToRead($userId, $keyring, $kek);

            return $this->keyringCache[$fingerprint] = $keyring;
        } finally {
            sodium_memzero($kek);
        }
    }

    // Re-writes a keyring that was encrypted with password-hardening cost, so
    // an existing install stops paying ~500ms per read. Opportunistic: the
    // keyring already decrypted fine, so a failure here must never turn a
    // successful load into an error.
    private function rewriteIfCostlyToRead(int $userId, GdkKeyring $keyring, string $kek): void
    {
        if ($keyring->epochs() === []) {
            return;
        }

        try {
            [, $memlimit] = $this->backupEncryptor->kdfParams($this->keyringPath($userId));

            if ($memlimit <= self::CHEAP_READ_MEMLIMIT) {
                return;
            }

            $this->writeKeyringFile($userId, $keyring, $kek);
        } catch (\Throwable) {
            // Left at the old cost. The next write upgrades it, and the
            // in-process cache means this load still pays it only once.
        }
    }

    // Keyed by the KEK itself, never by user alone: a withheld key never
    // reaches this method (release() returns null and the caller throws), and
    // a rotated or re-wrapped key yields a different entry rather than
    // resolving to a stale keyring.
    private function keyringCacheKey(int $userId, string $kek): string
    {
        // Plain bin2hex, NOT the injectable SodiumPrimitives: this is an array
        // key, not crypto, and routing it through the seam put a failure point
        // ahead of the translation that turns libsodium errors into
        // CryptoOperationFailedException.
        return $userId.':'.bin2hex(
            sodium_crypto_generichash($kek, '', self::CACHE_FINGERPRINT_BYTES),
        );
    }

    // Appends $epoch to the keyring WITHOUT discarding any prior epoch,
    // re-encrypts atomically, and advances current_epoch to $epoch->epochId.
    /**
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
            throw CryptoOperationFailedException::during('GDK epoch append', $e);
        } finally {
            sodium_memzero($kek);
        }
    }

    // Swaps the key held for $epoch->epochId, re-encrypts atomically, and
    // leaves current_epoch where it is. Reserved for adopting the group's
    // authoritative epoch over a local one that decrypts nothing — the
    // caller proves that before calling.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     */
    public function replaceEpoch(int $userId, GdkEpoch $epoch, Session $session): void
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            throw new \LogicException('Cannot replace GDK epoch: app-lock not unlocked.');
        }

        try {
            $keyring = $this->readKeyringFile($userId, $kek)->withEpochReplaced($epoch);
            $this->writeKeyringFile($userId, $keyring, $kek);
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('GDK epoch replace', $e);
        } finally {
            sodium_memzero($kek);
        }
    }

    // Resolves the epoch whose id equals current_epoch, paired with its raw
    // key from the keyring — the single accessor the write hooks consume.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws KeyringStateException when no current epoch is recorded, or
     *                               the keyring does not hold a key for it.
     */
    public function currentEpoch(int $userId, Session $session): GdkEpoch
    {
        $epochId = $this->currentEpochId($userId);
        if ($epochId === null) {
            throw KeyringStateException::noCurrentEpoch($userId);
        }

        // Through the memo, not readKeyringFile(): this runs on every write
        // hook, and decrypting the keyring per call is the cost loadKeyring()
        // was built to stop paying. It holds the app-lock check too, so the
        // KEK is released once here rather than twice.
        $keyHex = $this->loadKeyring($userId, $session)->keyFor($epochId);
        if ($keyHex === null) {
            throw KeyringStateException::missingKeyForEpoch($userId, $epochId);
        }

        return new GdkEpoch(epochId: $epochId, keyHex: $keyHex);
    }

    // Re-encrypts the SAME keyring contents (every epoch, unchanged) under
    // $newKek — raw wrap-key bytes the caller already holds directly,
    // bypassing AppLockKeyService::release(). After this call the OLD key
    // can no longer decrypt the file; only $newKek can.
    /**
     * @throws InvalidArgumentException when either key is empty.
     * @throws CryptoOperationFailedException when $oldKek cannot decrypt the current file.
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
            throw CryptoOperationFailedException::during('GDK keyring re-wrap', $e);
        } finally {
            sodium_memzero($oldKek);
            sodium_memzero($newKek);
        }
    }

    private function keyringPath(int $userId): string
    {
        return UserDataPathService::appPath("sync/gdk/{$userId}.enc");
    }

    // Declared, not swallowed: leaving the encryptor's own type off the
    // signature is how a wrong KEK escaped as far as the dashboard, since
    // nothing downstream could see that it was reachable.
    /**
     * @throws KeyringStateException when the decrypted payload will not parse.
     * @throws BackupDecryptionException when $kek cannot decrypt the file.
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
                throw KeyringStateException::corruptPayload($userId);
            }

            /** @var array<string, mixed> $payload */
            return GdkKeyring::fromArray($payload);
        } finally {
            @unlink($tmpPath);
        }
    }

    private function writeKeyringFile(int $userId, GdkKeyring $keyring, string $kek): void
    {
        // The file about to change is what loadKeyring() memoises, so drop the
        // cache wholesale — an appended epoch or a re-wrap must never resolve
        // to the keyring this process read before the write.
        $this->keyringCache = [];

        $encPath = $this->keyringPath($userId);
        $tmpEncPath = $this->stageKeyringFile($userId, $keyring, $kek);

        // Rename over the real path immediately — every OTHER caller of this
        // method does not need the deferred-finalize seam: none of them run
        // inside an ambient SQL transaction whose rollback could strand this file.
        if (! @rename($tmpEncPath, $encPath)) {
            @unlink($tmpEncPath);

            throw SecretFileException::couldNotFinalizeKeyring($userId);
        }
    }

    // Encrypts $keyring to a .tmp sibling of the real keyring path WITHOUT
    // renaming it into place — the shared staging primitive behind both
    // writeKeyringFile() (immediate finalize) and stageFirstEpoch() (deferred
    // finalize). Returns the .tmp path.
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

        // Randomized rather than a fixed `{userId}.enc.tmp` — a fixed path let
        // two concurrent stage/write operations, or a stale `.tmp` from a
        // crashed run, collide and silently overwrite each other.
        $tmpEncPath = $encPath.'.'.bin2hex(random_bytes(8)).'.tmp';

        try {
            // The KEK is 256 random bits, not a passphrase — encryptWithKey
            // skips the password-hardening cost that made every keyring read
            // ~500ms for no security gain.
            $this->backupEncryptor->encryptWithKey($tmpPlainPath, $tmpEncPath, $kek);
        } finally {
            @unlink($tmpPlainPath);
        }

        return $tmpEncPath;
    }

    // Upserts sync_encryption_state.current_epoch — NEVER any key material.
    // Sets enabled_at only the first time a row is created for this user
    // (i.e. when encryption is first enabled).
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
