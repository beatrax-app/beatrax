<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Resolver;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\UniqueSlug;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Normalizer;

/**
 * @link ../../../../.docs/features/counterparties/resolution-chain.md
 */
final readonly class CounterpartySlugResolver
{
    private const int SLUG_COLUMN_MAX_LENGTH = 128;

    private const string FALLBACK = 'counterparty';

    private const string SEPARATOR = '-';

    // Combining marks, and the zero-width characters //TRANSLIT passed over
    // without emitting its `?`. Every other unspellable character becomes a
    // word break below, so leaving these in would split a name on something
    // that was never visible in it.
    private const string INVISIBLE = '/[\p{Mn}\p{Me}\x{200B}\x{2060}-\x{2063}\x{FEFF}]+/u';

    // The characters a sweep of the whole BMP found every libc spelling with a
    // letter and voku/portable-ascii holding no entry for. Substituted before
    // decomposition: U+00B5 decomposes to Greek mu, which is not romanised.
    private const array TRANSLITERATION_GAPS = [
        '©' => '(c)',
        '®' => '(R)',
        'µ' => 'u',
        '×' => 'x',
        '•' => 'o',
        '℮' => 'e',
        '◦' => 'o',
    ];

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

    // Deliberately not UniqueSlug::slugify(): Str::slug() deletes the dot and
    // the slash this keeps as separators — coolblue-bv against coolblue-b-v —
    // and the slug is the firstOrCreate key, so swapping it would fork every
    // already-stored merchant into a second row on the next import.
    public static function slugify(string $value): string
    {
        $lower = strtolower(self::toAscii($value));
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

    // Compatibility decomposition strips the accent; Str::ascii() expands what
    // survives — the Latin letters that do not decompose and the punctuation
    // and currency signs. A letter of any OTHER script is not romanised: doing
    // so would rename every stored Cyrillic and Greek merchant.
    /**
     * @link ../../../../.docs/features/counterparties/slug-is-a-cross-platform-key.md
     */
    private static function toAscii(string $value): string
    {
        $substituted = strtr($value, self::TRANSLITERATION_GAPS);
        $decomposed = Normalizer::normalize($substituted, Normalizer::FORM_KD);
        $base = is_string($decomposed) ? $decomposed : $substituted;
        $withoutMarks = preg_replace(self::INVISIBLE, '', $base) ?? $base;

        return preg_replace_callback('/[^\x00-\x7F]/u', self::expand(...), $withoutMarks) ?? '';
    }

    // Anything with no ASCII spelling becomes a separator rather than nothing.
    // //TRANSLIT answered an unmappable character with `?`, which the cleanup
    // pass reads as a word break, so dropping it here would join the two words
    // either side of it and re-slug the merchant that carries one.
    /**
     * @param  array<string>  $match
     */
    private static function expand(array $match): string
    {
        $ascii = preg_match('/\p{Latin}|\P{L}/u', $match[0]) === 1 ? Str::ascii($match[0]) : '';

        return $ascii === '' ? self::SEPARATOR : $ascii;
    }
}
