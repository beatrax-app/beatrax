<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Community\Public\Services\CorpusPatternMatcher;
use Modules\Core\Public\Services\UserCountry;
use stdClass;

final class MerchantNameResolver
{
    // Keeps the PHP-side scan bounded whatever the dataset does.
    private const GENERALIZED_SCAN_LIMIT = 500;

    /** @var array<int, string> */
    private array $regionByUser = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CommunityCorpusQuery $corpus,
        private readonly UserCountry $countries,
    ) {}

    // The reader's own aliases are not region-scoped — they are theirs wherever
    // they live. Only the shared corpus is, because it holds every country's
    // merchants at once and short tokens collide across them.
    public function resolve(string $rawDescription, int $userId): ?string
    {
        $region = $this->regionFor($userId);

        return $this->userExactMatch($rawDescription, $userId)
            ?? $this->userGeneralizedMatch($rawDescription, $userId)
            ?? $this->corpus->lookupExact($rawDescription, $region)
            ?? $this->corpus->lookupGeneralized($rawDescription, $region)
            ?? $this->corpus->lookupRegex($rawDescription, $region);
    }

    // Empty when the reader has named no country, which widens to every region
    // rather than resolving nothing — the same fallback CounterpartyResolverService
    // makes for the government and bank-fee rules. Memoised because this runs
    // once per transaction across a whole import.
    private function regionFor(int $userId): string
    {
        return $this->regionByUser[$userId] ??= $this->countries->current($userId);
    }

    private function userExactMatch(string $rawDescription, int $userId): ?string
    {
        $exact = $this->db->connection()->table('merchant_aliases')
            ->where('user_id', $userId)
            ->where('pattern', $rawDescription)
            ->value('friendly_name');

        return is_string($exact) && $exact !== '' ? $exact : null;
    }

    // Longest alias first, for the same reason the corpus tier scans that way: a
    // reader who has both `albert` and `albert heijn` gets the one that describes
    // the line, not whichever they happened to save first.
    private function userGeneralizedMatch(string $rawDescription, int $userId): ?string
    {
        $haystack = mb_strtolower($rawDescription);

        /** @var iterable<stdClass> $generalized */
        $generalized = $this->db->connection()->table('merchant_aliases')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->limit(self::GENERALIZED_SCAN_LIMIT)
            ->get(['generalized_pattern', 'friendly_name']);

        $candidates = [];
        foreach ($generalized as $row) {
            $needle = is_string($row->generalized_pattern) ? $row->generalized_pattern : '';
            $friendly = is_string($row->friendly_name) ? $row->friendly_name : '';
            if ($needle !== '' && $friendly !== '') {
                $candidates[] = ['needle' => $needle, 'friendly' => $friendly];
            }
        }

        // usort is stable as of PHP 8.0, so two aliases of equal length keep the
        // order they were saved in and the answer stays deterministic.
        usort(
            $candidates,
            static fn (array $left, array $right): int => mb_strlen($right['needle']) <=> mb_strlen($left['needle']),
        );

        foreach ($candidates as $candidate) {
            // Whole token, as the corpus tier does: this tier is consulted
            // FIRST, so an unanchored match here wins before the corpus is even
            // asked and `obi` still renamed a phone bill after a DIY chain.
            if (CorpusPatternMatcher::containsToken($haystack, $candidate['needle'])) {
                return $candidate['friendly'];
            }
        }

        return null;
    }
}
