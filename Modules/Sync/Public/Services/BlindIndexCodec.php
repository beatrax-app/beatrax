<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Exceptions\BlindIndexKeyUnavailableException;
use RuntimeException;
use SodiumException;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final class BlindIndexCodec
{
    // Prefixed onto every digest so a value derived for one logical key can
    // never be replayed as a valid value for another, and so a future second
    // blind-index domain cannot be cross-matched against this one.
    public const string CONTEXT = 'beatrax-blind-index:v1';

    // The stored form is 64 lowercase hex characters, which is what makes it
    // fit `transactions.counterparty_normalized`'s varchar(80) unchanged.
    public const int DIGEST_LENGTH = 64;

    public function __construct(
        private readonly GdkKeyringService $keyringService,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    // Plaintext in, keyed digest out. Returns $plaintext UNCHANGED for a user
    // who has never enabled encryption — every other column of theirs is
    // plaintext too, and hashing only theirs would buy nothing while making
    // the enable-time sweep unable to tell converted rows from unconverted.
    /**
     * @throws BlindIndexKeyUnavailableException when encryption IS enabled and the key is not held.
     */
    public function derive(string $domain, string $plaintext, int $userId, Session $session): string
    {
        if (! $this->isEnrolled($userId)) {
            return $plaintext;
        }

        return $this->deriveWithKey($domain, $plaintext, $userId, $this->requireKeyHex($domain, $userId, $session));
    }

    // Same digest as derive(), over a key the caller already holds. The
    // re-derive sweep uses this so a whole table costs one keyring read
    // rather than one per row, and so it can hash under a key it is in the
    // middle of adopting.
    public function deriveWithKey(string $domain, string $plaintext, int $userId, string $keyHex): string
    {
        try {
            $rawKey = sodium_hex2bin($keyHex);
        } catch (SodiumException $e) {
            throw new RuntimeException('BlindIndexCodec: the stored blind-index key is not valid hex.', 0, $e);
        }

        try {
            return hash_hmac(
                'sha256',
                implode('|', [self::CONTEXT, $domain, (string) $userId, $plaintext]),
                $rawKey,
            );
        } finally {
            sodium_memzero($rawKey);
        }
    }

    // True once `sync_encryption_state.current_epoch` is set, which is a plain
    // integer readable with no key at all. That is deliberately NOT the same
    // question as "is the key held right now": the first says the user's rows
    // are supposed to be keyed, the second says this process can do it.
    public function isEnrolled(int $userId): bool
    {
        $row = $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->first(['current_epoch']);

        return $row !== null && is_numeric($row->current_epoch ?? null);
    }

    // Null rather than a throw, for the callers whose job is to decide what to
    // do about a missing key rather than to be stopped by it.
    public function keyHexOrNull(int $userId, Session $session): ?string
    {
        if (! $this->isEnrolled($userId)) {
            return null;
        }

        try {
            return $this->keyringService->ensureBlindIndexKey($userId, $session);
        } catch (LogicException|RuntimeException) {
            return null;
        }
    }

    // Whether this device has already rewritten its stored matching keys under
    // the key it holds. Once true, adopting a peer's different key would leave
    // every stored digest unmatchable by the value a re-import computes.
    public function hasDerived(int $userId): bool
    {
        $row = $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->first(['counterparty_key_backfilled_at']);

        return $row !== null && ($row->counterparty_key_backfilled_at ?? null) !== null;
    }

    public function markDerived(int $userId): void
    {
        $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->update(['counterparty_key_backfilled_at' => $this->clock->now()]);
    }

    // Shape, not proof: a merchant name of exactly this length and alphabet is
    // improbable but possible. Whether the sweep runs at all is decided by its
    // own marker; this only skips a row a re-entered sweep already converted,
    // and answers the narrower question of whether a human could read a value.
    public static function looksDerived(string $value): bool
    {
        return strlen($value) === self::DIGEST_LENGTH
            && preg_match('/^[0-9a-f]{'.self::DIGEST_LENGTH.'}$/', $value) === 1;
    }

    /**
     * @throws BlindIndexKeyUnavailableException
     */
    private function requireKeyHex(string $domain, int $userId, Session $session): string
    {
        $keyHex = $this->keyHexOrNull($userId, $session);
        if ($keyHex === null) {
            throw BlindIndexKeyUnavailableException::forUser($userId, $domain);
        }

        return $keyHex;
    }
}
