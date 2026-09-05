<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Resolver;

use Iban\Validation\Validator as IbanValidator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\PatternScan;
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

    // The base a name that spells an account number takes instead of itself.
    // Two such rows are still separated by the ordinary suffix walk, which
    // compares the holder's decrypted name, so matching is unchanged: the
    // same file re-imported lands back on the same row.
    public const string OPAQUE_BASE = 'unnamed';

    private const string SEPARATOR = '-';

    // ISO 13616 shape — two letters, two check digits, then up to thirty more
    // — beside the bare account number a file carries where no IBAN exists.
    // Asked of the SLUG rather than the name, because the slug is the stored
    // form and a migration has to ask the same question with no key held.
    private const string ACCOUNT_IDENTIFIER = '/^(?:[a-z]{2}\d{2}[a-z0-9]{11,30}|\d{9,})$/';

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
        private IbanValidator $ibanValidator,
    ) {}

    // $ownedBy is the row being renamed. An import has no row yet and asks by
    // name; a rename does, and two rows may legitimately carry the same name,
    // so answering by name there would move one row onto another's slug.
    public function resolveUnique(int $userId, string $displayName, ?int $ownedBy = null): string
    {
        return UniqueSlug::walk(
            $this->routableBase($displayName),
            fn (string $slug): bool => $this->slugIsFreeFor($userId, $slug, $displayName, $ownedBy),
        );
    }

    // The slug is a route segment and a plaintext column while `display_name`
    // is sealed, so a name that spells an account number publishes the one
    // value the profile keeps behind a Show-IBAN toggle. Every arm funnels
    // through here, so no future one can reintroduce it by choosing a name.
    private function routableBase(string $displayName): string
    {
        $base = self::slugify($displayName);

        return self::spellsAnAccountIdentifier($base) || $this->spellsAPresentedIban($displayName)
            ? self::OPAQUE_BASE
            : $base;
    }

    // A file writing the IBAN into the name column writes it the way a human
    // reads it, in groups of four, and those spaces survive as separators the
    // shape test cannot see through. The checksum is what tells that name
    // from a trading one merely opening with two letters and two digits.
    private function spellsAPresentedIban(string $displayName): bool
    {
        $compact = strtoupper(PatternScan::replace('/[\s'.self::SEPARATOR.']+/u', '', $displayName));

        return self::spellsAnAccountIdentifier(strtolower($compact))
            && $this->ibanValidator->validate($compact);
    }

    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-identity-columns-that-are-still-plaintext-and-what-it-would-take-to-fix-them
     */
    public static function spellsAnAccountIdentifier(string $slug): bool
    {
        return PatternScan::matches(self::ACCOUNT_IDENTIFIER, $slug);
    }

    // Free means unused, or already held by this same counterparty. The
    // stored name is decrypted before comparing: a raw ciphertext comparison
    // treats every re-import as a different holder and fragments one merchant
    // across bol, bol-2, bol-3 forever.
    private function slugIsFreeFor(int $userId, string $slug, string $displayName, ?int $ownedBy): bool
    {
        $existing = $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->first(['id', 'display_name']);

        if ($existing === null) {
            return true;
        }

        if ($ownedBy !== null) {
            $holderId = $existing->id ?? null;

            return is_numeric($holderId) && (int) $holderId === $ownedBy;
        }

        $storedName = $existing->display_name ?? null;

        return is_string($storedName) && $this->decryptDisplayName($storedName, $userId) === $displayName;
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
        $cleaned = PatternScan::replace('/[^a-z0-9]+/', '-', $lower);
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

        return PatternScan::replaceCallback('/[^\x00-\x7F]/u', self::expand(...), $withoutMarks);
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
        $ascii = preg_match('/[\p{Latin}\P{L}]/u', $match[0]) === 1 ? Str::ascii($match[0]) : '';

        return $ascii === '' ? self::SEPARATOR : $ascii;
    }
}
