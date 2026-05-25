<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Illuminate\Database\DatabaseManager;
use stdClass;

/**
 * Read-only query surface over the `community_merchant_mappings` table
 * filtered to the global tier (`user_id IS NULL`). Consumed by
 * `MerchantNameResolver` for the community-tail fallback steps (D-15
 * resolution rules c and d), by `MysteryMerchantsPage` for the corpus-
 * size stats strip, and by the SuggestMappingModal for the per-render
 * dedup check.
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

    public function __construct(private readonly DatabaseManager $db) {}

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

        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('community_merchant_mappings')
            ->whereNull('user_id')
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
