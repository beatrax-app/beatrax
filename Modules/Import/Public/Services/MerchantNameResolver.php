<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Community\Public\Services\CommunitySettings;
use Modules\Community\Public\Services\CorpusPatternMatcher;
use Modules\Core\Public\Services\UserCountry;
use stdClass;

final class MerchantNameResolver
{
    private const int GENERALIZED_SCAN_LIMIT = 500;

    /** @var array<int, array{exact: array<string, string>, generalized: list<array{needle: string, friendly: string}>}> */
    private array $aliasesByUser = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CommunityCorpusQuery $corpus,
        private readonly UserCountry $countries,
        private readonly CommunitySettings $community,
    ) {}

    // The reader's own aliases are not region-scoped — they are theirs wherever
    // they live. Only the shared corpus is, because it holds every country's
    // merchants at once and short tokens collide across them. The opt-out gates
    // the corpus tiers alone; a reader's own aliases are never community data.
    public function resolve(string $rawDescription, int $userId): ?string
    {
        $aliases = $this->aliasesFor($userId);
        $own = $aliases['exact'][$rawDescription]
            ?? self::generalizedMatch($aliases['generalized'], $rawDescription);

        if ($own !== null || ! $this->community->usesSharedList($userId)) {
            return $own;
        }

        $region = $this->regionFor($userId);

        return $this->corpus->lookupExact($rawDescription, $region)
            ?? $this->corpus->lookupGeneralized($rawDescription, $region)
            ?? $this->corpus->lookupRegex($rawDescription, $region);
    }

    // The alias list is memoised for the life of the container, so anything that
    // adds, edits or drops one of this reader's aliases has to say so here — the
    // next resolve otherwise answers from the list as it stood before the write.
    public function forget(int $userId): void
    {
        unset($this->aliasesByUser[$userId]);
    }

    // Empty when the reader has named no country, which widens to every region
    // rather than resolving nothing. The government and bank-fee tiers go the
    // other way and stay silent, because a shop trades anywhere and a tax
    // office does not. UserCountry holds the memo; a copy here outlived it.
    private function regionFor(int $userId): string
    {
        return $this->countries->current($userId);
    }

    // Both alias tiers read one memoised list, for the same reason the corpus
    // tiers hold theirs: a whole import asks once per transaction, and the two
    // reads and the sort were paid again on every one of them. Keyed by user
    // because this service is a singleton and the aliases are not shared.
    /**
     * @return array{exact: array<string, string>, generalized: list<array{needle: string, friendly: string}>}
     */
    private function aliasesFor(int $userId): array
    {
        if (isset($this->aliasesByUser[$userId])) {
            return $this->aliasesByUser[$userId];
        }

        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('merchant_aliases')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get(['pattern', 'generalized_pattern', 'friendly_name']);

        $exact = [];
        $generalized = [];
        $scanned = 0;
        foreach ($rows as $row) {
            $pattern = is_string($row->pattern) ? $row->pattern : '';
            $needle = is_string($row->generalized_pattern) ? $row->generalized_pattern : '';
            $friendly = is_string($row->friendly_name) ? $row->friendly_name : '';

            if ($friendly !== '' && ! array_key_exists($pattern, $exact)) {
                $exact[$pattern] = $friendly;
            }
            // The scan cap counts rows read, not candidates kept, because it
            // stood on the query that read them: an alias past the cap is
            // still matched exactly, and only the generalized tier stops.
            if ($scanned < self::GENERALIZED_SCAN_LIMIT && $needle !== '' && $friendly !== '') {
                $generalized[] = ['needle' => $needle, 'friendly' => $friendly];
            }
            $scanned++;
        }

        // usort is stable as of PHP 8.0, so two aliases of equal length keep the
        // order they were saved in and the answer stays deterministic.
        usort(
            $generalized,
            static fn (array $left, array $right): int => mb_strlen($right['needle']) <=> mb_strlen($left['needle']),
        );

        return $this->aliasesByUser[$userId] = ['exact' => $exact, 'generalized' => $generalized];
    }

    // Longest alias first, for the same reason the corpus tier scans that way: a
    // reader who has both `albert` and `albert heijn` gets the one that describes
    // the line, not whichever they happened to save first.
    /**
     * @param  list<array{needle: string, friendly: string}>  $generalized
     */
    private static function generalizedMatch(array $generalized, string $rawDescription): ?string
    {
        $haystack = mb_strtolower($rawDescription);

        foreach ($generalized as $candidate) {
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
