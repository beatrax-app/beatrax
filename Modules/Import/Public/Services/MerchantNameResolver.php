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
    // Keeps the PHP-side scan bounded whatever the dataset does.
    private const GENERALIZED_SCAN_LIMIT = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CommunityCorpusQuery $corpus,
    ) {}

    public function resolve(string $rawDescription, int $userId): ?string
    {
        return $this->userExactMatch($rawDescription, $userId)
            ?? $this->userGeneralizedMatch($rawDescription, $userId)
            ?? $this->corpus->lookupExact($rawDescription)
            ?? $this->corpus->lookupGeneralized($rawDescription)
            ?? $this->corpus->lookupRegex($rawDescription);
    }

    private function userExactMatch(string $rawDescription, int $userId): ?string
    {
        $exact = $this->db->connection()->table('merchant_aliases')
            ->where('user_id', $userId)
            ->where('pattern', $rawDescription)
            ->value('friendly_name');

        return is_string($exact) && $exact !== '' ? $exact : null;
    }

    private function userGeneralizedMatch(string $rawDescription, int $userId): ?string
    {
        $haystack = mb_strtolower($rawDescription);

        /** @var iterable<stdClass> $generalized */
        $generalized = $this->db->connection()->table('merchant_aliases')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->limit(self::GENERALIZED_SCAN_LIMIT)
            ->get(['generalized_pattern', 'friendly_name']);

        foreach ($generalized as $row) {
            $needle = is_string($row->generalized_pattern) ? $row->generalized_pattern : '';
            $friendly = is_string($row->friendly_name) ? $row->friendly_name : '';
            if ($needle !== '' && $friendly !== '' && mb_strpos($haystack, mb_strtolower($needle)) !== false) {
                return $friendly;
            }
        }

        return null;
    }
}
