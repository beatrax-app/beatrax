<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto\Concerns;

use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;
use Modules\Core\Public\Exceptions\BackupDecryptionException;
use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Exceptions\SecretFileException;
use SodiumException;

// The blind-index key rides in the same KEK-wrapped keyring file as the epoch
// keys but obeys none of their rules: minted once beside the first epoch,
// never rotated, single-valued. Every write here goes through the host's own
// writeKeyringFile(), which is where the decrypted-keyring memo is dropped.
/**
 * @link ../../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
trait ManagesBlindIndexKey
{
    // Fresh random bytes rather than anything derived from an epoch key:
    // rotation replaces epoch keys, and a blind index that moved with them
    // would stop matching rows written before the rotation.
    /**
     * @throws CryptoOperationFailedException on a libsodium failure.
     */
    private function mintBlindIndexKeyHex(): string
    {
        $raw = random_bytes(SODIUM_CRYPTO_GENERICHASH_KEYBYTES);

        try {
            return $this->sodium->binToHex($raw);
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('blind-index key generation', $e);
        } finally {
            sodium_memzero($raw);
        }
    }

    // Null distinguishes two states the caller must not conflate: encryption
    // was never enabled (no keyring file), or the keyring predates the blind
    // index. BlindIndexCodec::keyHexOrNull() is what tells them apart.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws BackupDecryptionException when the held KEK does not open the file.
     */
    public function blindIndexKeyHex(int $userId, Session $session): ?string
    {
        return $this->loadKeyring($userId, $session)->blindIndexKeyHex();
    }

    // Writes $keyHex as this user's blind-index key. The caller decides
    // whether the write is a first mint or the adoption of a peer's key, and
    // owns re-deriving any row already written under a different one.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws BackupDecryptionException when the held KEK does not open the file.
     * @throws SecretFileException when the re-written keyring cannot be finalized.
     */
    public function setBlindIndexKey(int $userId, string $keyHex, Session $session): void
    {
        if ($keyHex === '') {
            throw new InvalidArgumentException('GdkKeyringService::setBlindIndexKey — keyHex must not be empty.');
        }

        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            throw new \LogicException('Cannot set the GDK blind-index key: app-lock not unlocked.');
        }

        try {
            $keyring = $this->readKeyringFile($userId, $kek)->withBlindIndexKey($keyHex);
            $this->writeKeyringFile($userId, $keyring, $kek);
        } finally {
            sodium_memzero($kek);
        }
    }

    // Mints a blind-index key for a keyring written before the column existed,
    // and answers the one already held otherwise. Two devices that each reach
    // this independently converge on GdkEpochControlHandler's adoption rule.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws BackupDecryptionException when the held KEK does not open the file.
     * @throws CryptoOperationFailedException on a libsodium failure while minting.
     * @throws SecretFileException when the re-written keyring cannot be finalized.
     */
    public function ensureBlindIndexKey(int $userId, Session $session): string
    {
        $held = $this->blindIndexKeyHex($userId, $session);
        if ($held !== null) {
            return $held;
        }

        $minted = $this->mintBlindIndexKeyHex();
        $this->setBlindIndexKey($userId, $minted, $session);

        return $minted;
    }
}
