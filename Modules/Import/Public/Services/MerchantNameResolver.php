<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use stdClass;

/**
 * Resolves a raw bank-statement description to a user-chosen friendly
 * merchant name by walking a precedence stack of alias sources.
 *
 * The resolver is consulted at preview / list-render time only and
 * never mutates the stored `transactions.description`. Aliases are
 * pure presentation-layer rewrites: editing or deleting a row in
 * `Settings → Aliases` changes the rendered name immediately on the
 * next render without touching any ledger data.
 *
 * Precedence stack (walked top-to-bottom, first hit wins):
 *
 *   1. The user's exact `merchant_aliases.pattern` match keyed on the
 *      composite `(user_id, pattern)`. The pattern column stores the
 *      raw description as first seen, so an exact hit means the user
 *      owns a rename for this verbatim row.
 *   2. The user's generalized `merchant_aliases.generalized_pattern`
 *      match. The resolver pulls the user's alias set into PHP and
 *      runs an `mb_strpos` substring match — the same defence the
 *      Categorization RuleEvaluator uses to keep user-authored
 *      patterns out of any SQL LIKE expression.
 *   3. The community corpus's exact `pattern` match on rows with
 *      `user_id IS NULL`. The bundled YAML seeds the global tier;
 *      `CommunityCorpusQuery::lookupExact` runs the same explicit
 *      `whereNull('user_id')` filter the table model carries on every
 *      read.
 *   4. The community corpus's generalized-pattern substring match.
 *      Same lowercase mb_strpos posture as step 2 above, with the
 *      corpus rows substituted for the user's alias set.
 *   5. Resolution falls through to null when no alias / corpus row
 *      matches; the caller renders the italic-muted raw description
 *      fallback (`.desc-fallback`).
 *
 * Every query carries an explicit `where('user_id', $userId)` clause
 * even though MerchantAlias wears the BelongsToUser global scope. The
 * resolver runs from preview / list / queue-job contexts where the
 * global scope is not always bound, so per-user safety is enforced at
 * the query level too.
 *
 * The exact-then-generalized ordering matters: if both an exact alias
 * (`pattern='SHELL PIETER NIEUW *0123' → "Shell Pieter"`) and a
 * generalized alias (`generalized_pattern='shell' → "Shell"`) exist
 * for the same user, the exact alias wins. Inverting the order would
 * make a broad rename override a more specific one whenever both
 * matched the same row — the exact-first rule prevents that
 * over-matching foot-gun.
 */
final class MerchantNameResolver
{
    /**
     * Cap the generalized-alias scan at 500 rows per user. The cap is
     * defence-in-depth: a single user accumulating 500+ live aliases
     * would already indicate a settings-page misuse, but the bound
     * keeps the PHP-side scan O(1) memory regardless of dataset size.
     */
    private const GENERALIZED_SCAN_LIMIT = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CommunityCorpusQuery $corpus,
    ) {}

    /**
     * Returns the friendly name resolved for this raw description by
     * walking the precedence stack documented on the class. Returns
     * null when no user alias and no community corpus row matches.
     */
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
