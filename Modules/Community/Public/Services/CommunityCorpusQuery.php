<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Illuminate\Database\DatabaseManager;
use stdClass;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
final class CommunityCorpusQuery
{
    // Defence-in-depth caps keeping the per-render scan cost bounded even if
    // the bundled corpus grows well past its current size; regex patterns are
    // a small minority of the corpus, so REGEX_SCAN_LIMIT is a tighter bound.
    private const GENERALIZED_SCAN_LIMIT = 1000;

    private const REGEX_SCAN_LIMIT = 500;

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
