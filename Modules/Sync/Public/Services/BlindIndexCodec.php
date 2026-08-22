<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Exceptions\BlindIndexKeyMalformedException;
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

    // The one value a blind-index column holds in the clear: it records the
    // ABSENCE of a counterparty rather than naming one. Defined here, on the
    // public surface, because three modules compare a stored value against it.
    public const string SENTINEL = '_no_counterparty';

    // Re-exported from the registry rather than respelled: the ledger derives
    // under these names and this class validates against them, so one literal
    // in two places would hash the same plaintext two different ways.
    public const string DOMAIN_COUNTERPARTY_NORMALIZED = SensitiveFieldRegistry::DOMAIN_COUNTERPARTY_NORMALIZED;

    public const string DOMAIN_COUNTERPARTY_IBAN = SensitiveFieldRegistry::DOMAIN_COUNTERPARTY_IBAN;

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
     * @throws BlindIndexKeyMalformedException when the key IS held but is not valid hex.
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
    /**
     * @throws LogicException when $domain is not one the registry declares.
     * @throws BlindIndexKeyMalformedException when $keyHex is not valid hex.
     */
    public function deriveWithKey(string $domain, string $plaintext, int $userId, string $keyHex): string
    {
        self::requireKnownDomain($domain);

        try {
            $rawKey = sodium_hex2bin($keyHex);
        } catch (SodiumException $e) {
            throw BlindIndexKeyMalformedException::forUser($userId, $domain, $e);
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

        return $this->heldKeyHexOrNull($userId, $session);
    }

    // Split out so a caller that has ALREADY established enrolment does not
    // pay for the question twice. derive() runs once per registered column per
    // row, so on an import the duplicate was a second read of
    // sync_encryption_state on every one of them.
    private function heldKeyHexOrNull(int $userId, Session $session): ?string
    {
        try {
            return $this->keyringService->ensureBlindIndexKey($userId, $session);
        } catch (LogicException|RuntimeException) {
            return null;
        }
    }

    // Whether the one-time conversion sweep has run on this device. Answers
    // only that: a sweep over an empty ledger converts nothing and still counts,
    // which is what stops it rescanning three tables on every screen mount.
    public function hasSweptCounterpartyKeys(int $userId): bool
    {
        $row = $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->first(['counterparty_key_backfilled_at']);

        return $row !== null && ($row->counterparty_key_backfilled_at ?? null) !== null;
    }

    public function markCounterpartyKeysSwept(int $userId): void
    {
        $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->update(['counterparty_key_backfilled_at' => $this->clock->now()]);
    }

    // The message is separator-joined, and the trailing plaintext is the only
    // variable-length field, so injectivity holds exactly while no domain
    // carries a separator of its own. A closed set is what enforces that;
    // without it `derive('a|1|x', ...)` and `derive('a', ...)` can collide.
    /**
     * @throws LogicException when $domain is not one the registry declares.
     */
    public static function requireKnownDomain(string $domain): void
    {
        if (! in_array($domain, SensitiveFieldRegistry::blindIndexDomains(), true)) {
            throw new LogicException("BlindIndexCodec: '{$domain}' is not a declared blind-index domain.");
        }
    }

    // The blind-index columns, on the public surface for the guards outside
    // Sync that have to know which columns these are. SensitiveFieldRegistry
    // owns the list; this is the door, not a second copy.
    /**
     * @return array<string, list<string>> {table}.{column} => every domain its rows may derive under
     */
    public static function indexedColumns(): array
    {
        return SensitiveFieldRegistry::blindIndexColumns();
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
        $keyHex = $this->heldKeyHexOrNull($userId, $session);
        if ($keyHex === null) {
            throw BlindIndexKeyUnavailableException::forUser($userId, $domain);
        }

        return $keyHex;
    }
}
