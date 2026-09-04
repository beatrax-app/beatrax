<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Community\Public\Dto\MerchantContactDto;
use stdClass;

final class CommunityCorpusQuery
{
    /** @var list<string> */
    private const array CONTACT_COLUMNS = ['website', 'cancel_url', 'support_url', 'support_phone', 'support_email'];

    // The key a null or empty region memoises under: every region at once, for
    // a reader who has named no country.
    private const string ALL_REGIONS = '*';

    // The `LIMIT 1000` / `LIMIT 500` these scans once carried, ordered by id,
    // truncated the corpus in bundled-file order rather than sampling it: once
    // the corpus outgrew the cap, every country past it stopped resolving.
    /**
     * @var array<string, list<array{compiled: string, needle: string, ascii: bool, name: string}>>
     */
    private array $generalizedRows = [];

    /** @var array<string, list<array{pattern: string, name: string}>> */
    private array $regexRows = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CorpusPatternMatcher $matcher,
    ) {}

    // Region-scoped like ClassificationRuleProvider's government and bank-fee
    // rules already are: every region's corpus was consulted at once, so a Dutch
    // `Albert Heijn 1042` resolved to the Czech chain ALBERT — cz.yaml seeds
    // before nl.yaml and won the first-match scan on the lower id.
    public function lookupExact(string $rawDescription, ?string $region = null): ?string
    {
        $value = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->where('pattern', $rawDescription)
            ->where(static fn (Builder $query): Builder => self::inRegion($query, $region))
            ->value('name');

        return is_string($value) && $value !== '' ? $value : null;
    }

    // The rows arrive longest-needle-first, so the FIRST hit is the most specific
    // one and the scan can still short-circuit. `Albert Heijn 1042` matched both
    // the Czech `albert` and the Dutch `albert heijn`, and file-sort order handed
    // it to whichever seeded first.
    /**
     * @link ../../../../.docs/architecture/reads-bounded-by-the-user.md#6--the-corpus-scan-a-reader-who-named-no-country-pays
     */
    public function lookupGeneralized(string $rawDescription, ?string $region = null): ?string
    {
        // Asked once here rather than once per corpus row: matchesCompiled()
        // puts the same question to the same haystack every time round the
        // scan, and a haystack that is not UTF-8 answers no to all of them.
        if ($rawDescription === '' || ! mb_check_encoding($rawDescription, 'UTF-8')) {
            return null;
        }

        $carriesAFoldedLetter = self::carriesAFoldedAsciiLetter($rawDescription);

        foreach ($this->generalizedRows($region) as $row) {
            if ($row['ascii'] && ! $carriesAFoldedLetter && stripos($rawDescription, $row['needle']) === false) {
                continue;
            }

            // Whole token, not any substring: a bare search matched the corpus
            // token OBI inside "mobiel". The match is case-insensitive, so the
            // haystack is not lowered first.
            if (CorpusPatternMatcher::matchesCompiled($row['compiled'], $rawDescription)) {
                return $row['name'];
            }
        }

        return null;
    }

    // The compiled pattern is a preg_quote'd literal between two lookarounds,
    // so a match REQUIRES the needle verbatim and stripos is a sound filter,
    // not a guess — except that /iu folds these two onto plain s and k, which
    // stripos does not. A haystack holding either skips the filter entirely.
    private static function carriesAFoldedAsciiLetter(string $haystack): bool
    {
        return str_contains($haystack, "\u{017F}") || str_contains($haystack, "\u{212A}");
    }

    public function lookupRegex(string $rawDescription, ?string $region = null): ?string
    {
        foreach ($this->regexRows($region) as $row) {
            if ($this->matcher->matches($row['pattern'], $rawDescription)) {
                return $row['name'];
            }
        }

        return null;
    }

    // Regex rows carry an empty generalized_pattern and are matched only by
    // lookupRegex(), so they are excluded once here rather than per call.
    /**
     * @return list<array{compiled: string, name: string}>
     */
    private function generalizedRows(?string $region): array
    {
        $key = self::memoKey($region);

        if (isset($this->generalizedRows[$key])) {
            return $this->generalizedRows[$key];
        }

        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->where('generalized_pattern', '!=', '')
            ->where(static fn (Builder $query): Builder => self::inRegion($query, $region))
            ->orderBy('id')
            ->get(['generalized_pattern', 'name']);

        $loaded = [];
        foreach ($rows as $row) {
            $needle = is_string($row->generalized_pattern) ? $row->generalized_pattern : '';
            $name = is_string($row->name) ? $row->name : '';
            if ($needle === '' || $name === '') {
                continue;
            }
            $loaded[] = ['needle' => mb_strtolower($needle), 'name' => $name];
        }

        return $this->generalizedRows[$key] = self::compiled(self::specificFirst($loaded));
    }

    // The needle-only half of a token match — the encoding check, the
    // alphanumeric check, the two edge probes and the quoting — is a function of
    // the needle alone, so it is paid once per corpus load here rather than once
    // per description scanned. A needle that compiles to null never matches.
    /**
     * @param  list<array{needle: string, name: string}>  $rows
     * @return list<array{compiled: string, needle: string, ascii: bool, name: string}>
     */
    private static function compiled(array $rows): array
    {
        $compiled = [];
        foreach ($rows as $row) {
            $pattern = CorpusPatternMatcher::compileToken($row['needle']);
            if ($pattern === null) {
                continue;
            }
            $compiled[] = [
                'compiled' => $pattern,
                'needle' => $row['needle'],
                'ascii' => preg_match('/[^\x00-\x7F]/', $row['needle']) !== 1,
                'name' => $row['name'],
            ];
        }

        return $compiled;
    }

    /**
     * @return list<array{pattern: string, name: string}>
     */
    private function regexRows(?string $region): array
    {
        $key = self::memoKey($region);

        if (isset($this->regexRows[$key])) {
            return $this->regexRows[$key];
        }

        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->where('pattern', 'like', CorpusPatternMatcher::REGEX_PREFIX.'%')
            ->where(static fn (Builder $query): Builder => self::inRegion($query, $region))
            ->orderBy('id')
            ->get(['pattern', 'name']);

        $loaded = [];
        foreach ($rows as $row) {
            $pattern = is_string($row->pattern) ? $row->pattern : '';
            $name = is_string($row->name) ? $row->name : '';
            if ($pattern === '' || $name === '') {
                continue;
            }
            $loaded[] = ['pattern' => $pattern, 'name' => $name];
        }

        return $this->regexRows[$key] = $loaded;
    }

    // Longest needle first, ties broken by the order the corpus loaded in, so the
    // scan below is deterministic and a broader pattern can never take a
    // description a narrower one also matches. Sorted once per memo key rather
    // than compared per lookup.
    /**
     * @param  list<array{needle: string, name: string}>  $rows
     * @return list<array{needle: string, name: string}>
     */
    private static function specificFirst(array $rows): array
    {
        // usort is stable as of PHP 8.0, so equal-length needles keep the order
        // the corpus loaded them in and the scan stays deterministic.
        usort(
            $rows,
            static fn (array $left, array $right): int => mb_strlen($right['needle']) <=> mb_strlen($left['needle']),
        );

        return $rows;
    }

    // The pan-European file, whose own header says a row there is answered to
    // every country at once. CorpusLoader stamps its 196 rows with a region
    // code matching no country, so naming your country deleted the whole
    // cross-border brand list while the national file still worked.
    private const string GLOBAL_REGION = 'EU';

    // A row claiming no region belongs to every reader: the column is nullable
    // and CorpusLoader leaves it empty for a file it could not read a code from,
    // so excluding those would drop mappings nobody meant to scope.
    private static function inRegion(Builder $query, ?string $region): Builder
    {
        $wanted = self::memoKey($region);

        if ($wanted === self::ALL_REGIONS) {
            return $query;
        }

        return $query->where('region', $wanted)
            ->orWhere('region', self::GLOBAL_REGION)
            ->orWhereNull('region')
            ->orWhere('region', '');
    }

    // The corpus stores what CorpusLoader read off the filename, upper-cased;
    // UserCountry hands back the lowercase ISO code the reader picked.
    private static function memoKey(?string $region): string
    {
        return $region === null || $region === '' ? self::ALL_REGIONS : strtoupper($region);
    }

    public function contactForMerchant(string $name): ?MerchantContactDto
    {
        // Keyed on the resolved merchant NAME, not the raw descriptor: many
        // descriptor variants collapse to one name, and a profile page holds
        // the name, never the matched row.

        // Requiring one populated column stops a brand whose lowest-id row
        // carries no contact data from masking a sibling row that does.
        if (trim($name) === '') {
            return null;
        }

        /** @var stdClass|null $row */
        $row = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->where('name', $name)
            ->where(static function (Builder $query): void {
                foreach (self::CONTACT_COLUMNS as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->orderBy('id')
            ->first(self::CONTACT_COLUMNS);

        return $row === null ? null : self::toContact($row);
    }

    private static function toContact(stdClass $row): MerchantContactDto
    {
        return new MerchantContactDto(
            website: self::str($row, 'website'),
            cancelUrl: self::str($row, 'cancel_url'),
            supportUrl: self::str($row, 'support_url'),
            supportPhone: self::str($row, 'support_phone'),
            supportEmail: self::str($row, 'support_email'),
        );
    }

    private static function str(stdClass $row, string $column): ?string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function mappingsCount(): int
    {
        return $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->count();
    }

    // The reader's own rows, which nothing else in the corpus reads. Their
    // count is what "Your contributions" means; the shared list's contributor
    // count above answers a different question.
    public function contributionsCount(int $userId): int
    {
        return $this->db->connection()->table('community_merchant_mappings')
            ->where('user_id', $userId)
            ->count();
    }

    public function contributorsCount(): int
    {
        return $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->distinct()
            ->count('contributor');
    }
}
