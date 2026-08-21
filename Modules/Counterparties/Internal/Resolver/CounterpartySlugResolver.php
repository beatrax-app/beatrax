<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Resolver;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\UniqueSlug;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/counterparties/resolution-chain.md
 */
final readonly class CounterpartySlugResolver
{
    private const int SLUG_COLUMN_MAX_LENGTH = 128;

    private const string FALLBACK = 'counterparty';

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        // A factory, not the session: resolving a session builds the encrypter,
        // and Artisan constructs this class merely to list a console command.
        private SessionFactory $session,
    ) {}

    public function resolveUnique(int $userId, string $displayName): string
    {
        return UniqueSlug::walk(
            self::slugify($displayName),
            fn (string $slug): bool => $this->slugIsFreeFor($userId, $slug, $displayName),
        );
    }

    // Free means unused, or already held by this same counterparty. The
    // stored name is decrypted before comparing: a raw ciphertext comparison
    // treats every re-import as a different holder and fragments one merchant
    // across bol, bol-2, bol-3 forever.
    private function slugIsFreeFor(int $userId, string $slug, string $displayName): bool
    {
        $existing = $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->value('display_name');

        return $existing === null
            || (is_string($existing) && $this->decryptDisplayName($existing, $userId) === $displayName);
    }

    // Never throws: an undecryptable value comes back as raw ciphertext,
    // which fails the identity comparison and falls through to suffixing.
    private function decryptDisplayName(string $stored, int $userId): string
    {
        return $this->codec->decryptValue('counterparties', 'display_name', $stored, $userId, ($this->session)())['value'];
    }

    // Deliberately not UniqueSlug::slugify(). Str::slug and this iconv walk
    // disagree on accented names — cafe-ambiance against caf-e-ambiance — and
    // the slug is the firstOrCreate key, so swapping it would fork every
    // already-stored merchant into a second row on the next import.
    public static function slugify(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = strtolower($ascii === false ? '' : $ascii);
        $cleaned = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';
        $trimmed = trim($cleaned, '-');

        if ($trimmed === '') {
            return self::FALLBACK;
        }

        // The cut is the width of the slug column that carries the UNIQUE.
        // The numeric suffix is appended after it, so a collision on a
        // 128-character base overruns the declared width.
        return substr($trimmed, 0, self::SLUG_COLUMN_MAX_LENGTH);
    }
}
