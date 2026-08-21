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
    private const CONTACT_COLUMNS = ['website', 'cancel_url', 'support_url', 'support_phone', 'support_email'];

    // The `LIMIT 1000` / `LIMIT 500` these scans once carried, ordered by id,
    // truncated the corpus in bundled-file order rather than sampling it: once
    // the corpus outgrew the cap, every country past it stopped resolving.
    /**
     * @var list<array{needle: string, name: string}>|null
     */
    private ?array $generalizedRows = null;

    /** @var list<array{pattern: string, name: string}>|null */
    private ?array $regexRows = null;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CorpusPatternMatcher $matcher,
    ) {}

    public function lookupExact(string $rawDescription): ?string
    {
        $value = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->where('pattern', $rawDescription)
            ->value('name');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function lookupGeneralized(string $rawDescription): ?string
    {
        foreach ($this->generalizedRows() as $row) {
            // Whole token, not any substring: a bare search matched the corpus
            // token OBI inside "mobiel". The match is case-insensitive, so the
            // haystack is no longer lowered first.
            if (CorpusPatternMatcher::containsToken($rawDescription, $row['needle'])) {
                return $row['name'];
            }
        }

        return null;
    }

    public function lookupRegex(string $rawDescription): ?string
    {
        foreach ($this->regexRows() as $row) {
            if ($this->matcher->matches($row['pattern'], $rawDescription)) {
                return $row['name'];
            }
        }

        return null;
    }

    // Regex rows carry an empty generalized_pattern and are matched only by
    // lookupRegex(), so they are excluded once here rather than per call.
    /**
     * @return list<array{needle: string, name: string}>
     */
    private function generalizedRows(): array
    {
        if ($this->generalizedRows !== null) {
            return $this->generalizedRows;
        }

        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->where('generalized_pattern', '!=', '')
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

        return $this->generalizedRows = $loaded;
    }

    /**
     * @return list<array{pattern: string, name: string}>
     */
    private function regexRows(): array
    {
        if ($this->regexRows !== null) {
            return $this->regexRows;
        }

        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->where('pattern', 'like', CorpusPatternMatcher::REGEX_PREFIX.'%')
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

        return $this->regexRows = $loaded;
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

    public function contributorsCount(): int
    {
        return $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->distinct()
            ->count('contributor');
    }
}
