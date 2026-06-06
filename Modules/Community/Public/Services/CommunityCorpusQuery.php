<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Illuminate\Database\DatabaseManager;
use stdClass;

/**
 * Read-only query surface over the `community_merchant_mappings` table
 * filtered to the global tier (`user_id IS NULL`). Consumed by
 * `MerchantNameResolver` for the community-tail fallback steps (the
 * exact-then-generalized corpus lookup that runs after the per-user
 * alias tiers), by `MysteryMerchantsPage` for the corpus-size stats
 * strip, and by the SuggestMappingModal for the per-render dedup check.
 *
 * Generalized-pattern matching is performed in PHP via `mb_strpos` —
 * never via SQL LIKE — mirroring the Categorization RuleEvaluator
 * posture and the docblock contract on `MerchantNameResolver`. Pattern
 * values therefore never reach a SQL string, so a malicious YAML entry
 * cannot exfiltrate data through a SQL-LIKE wildcard injection.
 */
final class CommunityCorpusQuery
{
    /**
     * Cap the generalized scan at 1000 corpus rows to keep the per-
     * render cost bounded even if the bundled corpus grows to several
     * thousand entries in a future snapshot. The cap is defence-in-
     * depth: the bundled corpus today is well under a hundred entries.
     */
    private const GENERALIZED_SCAN_LIMIT = 1000;

    /**
     * Cap the regex scan at 500 corpus rows. Regex patterns are a small
     * minority of the bundled corpus (store-number / structured-descriptor
     * cases), so the bound is comfortably above the real count.
     */
    private const REGEX_SCAN_LIMIT = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CorpusPatternMatcher $matcher,
    ) {}

    /**
     * Look up the friendly name for a verbatim raw bank-statement
     * description on the global corpus tier. Returns null when no
     * row matches.
     */
    public function lookupExact(string $rawDescription): ?string
    {
        $value = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->where('pattern', $rawDescription)
            ->value('name');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Look up the friendly name by matching the corpus's
     * `generalized_pattern` substring inside the lowercased raw
     * description. Walks the global corpus in id order and returns
     * the first match.
     */
    public function lookupGeneralized(string $rawDescription): ?string
    {
        $haystack = mb_strtolower($rawDescription);

        // Regex rows carry an empty generalized_pattern and are matched only
        // by lookupRegex(); excluding them here keeps the LIMIT reflecting
        // genuinely substring-matchable rows.
        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->where('generalized_pattern', '!=', '')
            ->orderBy('id')
            ->limit(self::GENERALIZED_SCAN_LIMIT)
            ->get(['generalized_pattern', 'name']);

        foreach ($rows as $row) {
            $needle = is_string($row->generalized_pattern) ? $row->generalized_pattern : '';
            $name = is_string($row->name) ? $row->name : '';
            if ($needle === '' || $name === '') {
                continue;
            }
            if (mb_strpos($haystack, mb_strtolower($needle)) !== false) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Look up the friendly name by matching the corpus's `regex:` patterns
     * against the raw description via CorpusPatternMatcher. Only rows whose
     * pattern carries the `regex:` prefix are scanned — the `like 'regex:%'`
     * operand is a fixed constant, never a corpus value, so it carries no
     * SQL-LIKE injection surface (the prefix is the matcher's, not user
     * input). Returns the first match in id order.
     */
    public function lookupRegex(string $rawDescription): ?string
    {
        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->where('pattern', 'like', CorpusPatternMatcher::REGEX_PREFIX.'%')
            ->orderBy('id')
            ->limit(self::REGEX_SCAN_LIMIT)
            ->get(['pattern', 'name']);

        foreach ($rows as $row) {
            $pattern = is_string($row->pattern) ? $row->pattern : '';
            $name = is_string($row->name) ? $row->name : '';
            if ($pattern === '' || $name === '') {
                continue;
            }
            if ($this->matcher->matches($pattern, $rawDescription)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Total count of global corpus rows. Surfaced as the "shared list"
     * KPI on `/community/mystery-merchants`.
     */
    public function mappingsCount(): int
    {
        return $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->count();
    }

    /**
     * Distinct contributor count across the global corpus tier.
     * Surfaced alongside `mappingsCount()` in the page stats strip.
     */
    public function contributorsCount(): int
    {
        return $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
            ->distinct()
            ->count('contributor');
    }
}
