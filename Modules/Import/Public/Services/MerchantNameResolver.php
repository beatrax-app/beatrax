<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use stdClass;

/**
 * @link ../../../../.docs/features/import/architecture.md#merchant-aliases
 */
final class MerchantNameResolver
{
    // Defence-in-depth: 500+ live aliases would already indicate a
    // settings-page misuse, but the bound keeps the PHP-side scan O(1)
    // memory regardless of dataset size.
    private const GENERALIZED_SCAN_LIMIT = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CommunityCorpusQuery $corpus,
    ) {}

    public function resolve(string $rawDescription, int $userId): ?string
    {
        $connection = $this->db->connection();

        $exact = $connection->table('merchant_aliases')
            ->where('user_id', $userId)
            ->where('pattern', $rawDescription)
            ->value('friendly_name');
        if (is_string($exact) && $exact !== '') {
            return $exact;
        }

        $haystack = mb_strtolower($rawDescription);

        /** @var iterable<stdClass> $generalized */
        $generalized = $connection->table('merchant_aliases')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->limit(self::GENERALIZED_SCAN_LIMIT)
            ->get(['generalized_pattern', 'friendly_name']);

        foreach ($generalized as $row) {
            $needle = is_string($row->generalized_pattern) ? $row->generalized_pattern : '';
            $friendly = is_string($row->friendly_name) ? $row->friendly_name : '';
            if ($needle === '' || $friendly === '') {
                continue;
            }
            if (mb_strpos($haystack, mb_strtolower($needle)) !== false) {
                return $friendly;
            }
        }

        $communityExact = $this->corpus->lookupExact($rawDescription);
        if ($communityExact !== null) {
            return $communityExact;
        }

        $communityGeneralized = $this->corpus->lookupGeneralized($rawDescription);
        if ($communityGeneralized !== null) {
            return $communityGeneralized;
        }

        $communityRegex = $this->corpus->lookupRegex($rawDescription);
        if ($communityRegex !== null) {
            return $communityRegex;
        }

        return null;
    }
}
