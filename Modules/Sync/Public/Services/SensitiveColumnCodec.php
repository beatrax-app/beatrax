<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use LogicException;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyring;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Exceptions\KeyringState;
use Modules\Sync\Internal\Exceptions\KeyringStateException;
use Modules\Sync\Public\Dto\DecryptedRow;
use Modules\Sync\Public\Exceptions\SensitiveColumnKeyUnavailableException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SodiumException;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final class SensitiveColumnCodec
{
    // One alarm per user per table per process. The refusal itself reaches
    // every caller through the exception; this is the alarm, and an op-log
    // drain over a sealed ledger would otherwise write one line per row into a
    // daily log nobody could then read.
    /** @var array<string, true> */
    private array $refusalsAlarmed = [];

    public function __construct(
        private readonly OpLogFieldCrypto $crypto,
        private readonly GdkKeyringService $keyringService,
        private readonly SensitiveFieldRegistry $registry,
        private readonly LoggerInterface $log,
    ) {}

    // PUBLIC and STATIC so other call sites (e.g. a raw SQL migration pass)
    // can reproduce the exact same AD independently without instantiating the full codec.
    public static function associatedData(string $table, string $field, int $epochId): string
    {
        return "{$table}:{$field}:{$epochId}";
    }

    // The op-log shape, which binds the row's primary key as well. Writer and
    // verifier have to emit identical bytes: one byte apart and decrypt()
    // returns false, which quarantines the entry with nothing a user would
    // ever see — so both sides read the shape from here, not from memory.
    public static function opLogAssociatedData(string $table, int|string $pk, string $field, int $epochId): string
    {
        return "{$table}:{$pk}:{$field}:{$epochId}";
    }

    // For a caller that seals one named column itself rather than handing this
    // codec a whole attribute array: it has to know which of its own fields to
    // route through encryptValue(), and encryptValue() seals whatever it is
    // given rather than consulting the registry.
    public function isEncrypted(string $table, string $field): bool
    {
        return $this->registry->isSensitive($table, $field);
    }

    // Non-throwing, for a caller whose job is to decide what to do about a
    // missing key rather than to be stopped by it. The console reindex asked
    // this by encrypting a probe and comparing; encryptValue() now refuses, so
    // that trick would throw in the very state it existed to detect.
    public function canSeal(int $userId, Session $session): bool
    {
        return $this->tryCurrentEpoch($userId, $session) !== null;
    }

    // Pass-through (returns $value unchanged) only for a user who has never
    // enabled encryption. Once `current_epoch` is set, an unreachable key is a
    // refusal, never a quiet write in the clear.
    /**
     * @throws SensitiveColumnKeyUnavailableException when encryption IS enabled and no key is held.
     */
    public function encryptValue(string $table, string $field, string $value, int $userId, Session $session): string
    {
        $current = $this->sealingEpoch($userId, $session, $table, [$field]);
        if ($current === null) {
            return $value;
        }

        return $this->encryptWithEpoch($table, $field, $value, $current);
    }

    // Non-sensitive or non-string entries are left untouched, and an $attrs
    // carrying none of them is never refused: the caller is writing a row this
    // codec has no opinion about.
    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     *
     * @throws SensitiveColumnKeyUnavailableException when encryption IS enabled and no key is held.
     */
    public function encryptAttrs(string $table, array $attrs, int $userId, Session $session): array
    {
        $current = $this->sealingEpoch($userId, $session, $table, $this->sensitiveFieldsIn($table, $attrs));
        if ($current === null) {
            return $attrs;
        }

        foreach ($attrs as $field => $value) {
            if (! is_string($value)) {
                continue;
            }
            if (! $this->registry->isSensitive($table, $field)) {
                continue;
            }
            $attrs[$field] = $this->encryptWithEpoch($table, $field, $value, $current);
        }

        return $attrs;
    }

    // Tries EVERY epoch in the keyring (rotation-safe). Returns the raw
    // stored value with `decrypted: false` when no epoch verifies
    // (tampering, corruption, or a legacy plaintext value) — NEVER throws.
    /**
     * @return array{value: string, decrypted: bool}
     */
    public function decryptValue(string $table, string $field, string $value, int $userId, Session $session): array
    {
        $keyring = $this->tryLoadKeyring($userId, $session);
        if ($keyring === null) {
            return $this->undecryptable($value, $userId, $session);
        }

        return $this->decryptWithKeyring($table, $field, $value, $keyring);
    }

    // Columns that fail to verify under every epoch keep their raw stored
    // value; the ones this codec had to blank are named by isUnreadable(), so
    // no caller has to read that back out of an empty string. NEVER throws.
    /**
     * @param  array<string, mixed>  $row
     */
    public function decryptRow(string $table, array $row, int $userId, Session $session): DecryptedRow
    {
        $keyring = $this->tryLoadKeyring($userId, $session);
        $unreadable = [];

        foreach ($row as $field => $value) {
            if (! is_string($value)) {
                continue;
            }
            if (! $this->registry->isSensitive($table, $field)) {
                continue;
            }
            // Same treatment with or without a keyring: without one, a
            // sensitive column holding ciphertext is unreadable here too, and
            // returning the row untouched put base64 straight on the screen.
            $opened = $keyring === null
                ? $this->undecryptable($value, $userId, $session)
                : $this->decryptWithKeyring($table, $field, $value, $keyring);

            $row[$field] = $opened['value'];

            // Keyed off the blanking itself rather than re-running the shape
            // test: the two disagree for a value that looks like ciphertext
            // and was handed back anyway, which is the whole point below. The
            // decrypted flag stays in it, or a sealed empty note reads as lost.
            if (! $opened['decrypted'] && $value !== '' && $opened['value'] === '') {
                $unreadable[] = $field;
            }
        }

        return new DecryptedRow($row, $unreadable);
    }

    private function encryptWithEpoch(string $table, string $field, string $value, GdkEpoch $epoch): string
    {
        $rawKey = sodium_hex2bin($epoch->keyHex);
        try {
            return $this->crypto->encrypt(
                $value,
                $rawKey,
                self::associatedData($table, $field, $epoch->epochId),
            );
        } finally {
            sodium_memzero($rawKey);
        }
    }

    /**
     * @return array{value: string, decrypted: bool}
     */
    private function decryptWithKeyring(string $table, string $field, string $value, GdkKeyring $keyring): array
    {
        foreach ($keyring->epochs() as $epoch) {
            try {
                $rawKey = sodium_hex2bin($epoch->keyHex);
            } catch (SodiumException) {
                continue;
            }

            try {
                $plain = $this->crypto->decrypt(
                    $value,
                    $rawKey,
                    self::associatedData($table, $field, $epoch->epochId),
                );
            } finally {
                sodium_memzero($rawKey);
            }

            if ($plain !== false) {
                return ['value' => $plain, 'decrypted' => true];
            }
        }

        // A keyring exists and nothing opened this value: either a pre-
        // encryption row (plaintext) or ciphertext whose epoch this device
        // lacks. Passing it through is right for the first, wrong for the
        // second — a phone that lost its keyring rendered base64 as names.

        // Shape decides: ciphertext is base64(nonce||ct), so at least 40 bytes
        // and no spaces. "ALBERT HEIJN 1234" fails on the space alone.
        return self::safeUndecrypted($value);
    }

    // Without a keyring the shape test is not evidence, it is a coin toss:
    // any 54+ characters of letters and digits whose length divides by four
    // decode as base64, and "AbonnementSpotifyPremiumFamilyPlanMaandelijks"
    // is a description a bank really exports.
    /**
     * @return array{value: string, decrypted: bool}
     */
    private function undecryptable(string $value, int $userId, Session $session): array
    {
        // Shape first because it costs nothing and never misses real
        // ciphertext; enrolment is the authority, and is only worth a query
        // once something is about to be blanked on its say-so.
        if (! self::looksLikeCiphertext($value) || ! $this->everEnrolled($userId, $session)) {
            return ['value' => $value, 'decrypted' => false];
        }

        return ['value' => '', 'decrypted' => false];
    }

    // encryptValue() writes plaintext for exactly one user — the one who never
    // enabled encryption — so for that user a stored value IS the plaintext,
    // whatever it looks like. Mirrors sealingEpoch()'s reading of the same
    // three states, minus the refusal.
    private function everEnrolled(int $userId, Session $session): bool
    {
        try {
            $this->keyringService->currentEpoch($userId, $session);
        } catch (KeyringStateException $e) {
            return $e->state !== KeyringState::NoCurrentEpoch;
        } catch (LogicException|RuntimeException) {
            return true;
        }

        return true;
    }

    // The no-matching-epoch path only: a keyring was held, so this user is
    // enrolled and the shape test is the whole question.
    /**
     * @return array{value: string, decrypted: bool}
     */
    private static function safeUndecrypted(string $value): array
    {
        return [
            'value' => self::looksLikeCiphertext($value) ? '' : $value,
            'decrypted' => false,
        ];
    }

    // Deliberately conservative: it must never blank a real description, so
    // anything that could plausibly be human text passes through untouched.
    private static function looksLikeCiphertext(string $value): bool
    {
        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value) !== 1) {
            return false;
        }

        $decoded = base64_decode($value, true);

        return $decoded !== false && strlen($decoded) >= 40;
    }

    // The two states tryCurrentEpoch() collapses into one null are opposites:
    // no `current_epoch` means every other column of this user's is plaintext
    // too and writing one more is correct; `current_epoch` set means the
    // ledger is sealed and this process simply cannot reach the key.
    /**
     * @param  list<string>  $fields
     *
     * @throws SensitiveColumnKeyUnavailableException
     */
    private function refuse(int $userId, string $table, array $fields): never
    {
        $alarm = "{$userId}:{$table}";
        if (! isset($this->refusalsAlarmed[$alarm])) {
            $this->refusalsAlarmed[$alarm] = true;
            $this->log->warning(
                'SensitiveColumnCodec: refused an unsealable write — encryption is enabled but no epoch key is held.',
                ['userId' => $userId, 'table' => $table, 'fields' => $fields],
            );
        }

        throw SensitiveColumnKeyUnavailableException::forColumns($userId, $table, $fields);
    }

    // One read, not two. currentEpoch() already separates "never enrolled" from
    // "sealed and unreachable" by the state it throws with, and asking the
    // second question again cost an extra sync_encryption_state select on every
    // written row of every install that has encryption switched off.
    /**
     * @param  list<string>  $fields
     */
    private function sealingEpoch(int $userId, Session $session, string $table, array $fields): ?GdkEpoch
    {
        try {
            return $this->keyringService->currentEpoch($userId, $session);
        } catch (KeyringStateException $e) {
            $enrolled = $e->state !== KeyringState::NoCurrentEpoch;
        } catch (LogicException|RuntimeException) {
            // Raised past the epoch lookup, so an epoch exists and the ledger
            // this writer is adding to is already sealed.
            $enrolled = true;
        }

        if ($enrolled && $fields !== []) {
            $this->refuse($userId, $table, $fields);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return list<string>
     */
    private function sensitiveFieldsIn(string $table, array $attrs): array
    {
        $fields = [];
        foreach ($attrs as $field => $value) {
            if (is_string($value) && $this->registry->isSensitive($table, $field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function tryCurrentEpoch(int $userId, Session $session): ?GdkEpoch
    {
        try {
            return $this->keyringService->currentEpoch($userId, $session);
        } catch (LogicException|RuntimeException) {
            return null;
        }
    }

    // RuntimeException too, matching tryCurrentEpoch. A held key that cannot
    // open the keyring raises BackupDecryptionException, which escaped — so
    // one wrong key 500'd every screen reading an encrypted column, with no
    // way back to the lock that would replace it.
    private function tryLoadKeyring(int $userId, Session $session): ?GdkKeyring
    {
        try {
            $keyring = $this->keyringService->loadKeyring($userId, $session);
        } catch (LogicException|RuntimeException) {
            return null;
        }

        return $keyring->epochs() === [] ? null : $keyring;
    }
}
