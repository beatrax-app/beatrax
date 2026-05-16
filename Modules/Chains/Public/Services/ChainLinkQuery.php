<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Chains\Public\Dto\ChainLinkRow;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\ChainTreeNode;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public read API over `chain_links` — assembles the chain drawer's
 * top-down tree, the top-nav badge integer, and the cursor-paginated
 * review-queue row list.
 *
 * Tree assembly walks `chain_links` from the root `transaction_id`
 * following `from_transaction_id → to_transaction_id` edges, filtered
 * to states ∈ {`confirmed`, `candidate`} (rejected legs are
 * suppressed). The walk is bounded at MAX_DEPTH=5 because the payment
 * topology this project models is at most five levels deep
 * (`recurring → expense → bulk-settle → ASN-transfer → funding source`),
 * with a visited-set guard for accidental cycle defence.
 *
 * `to_transaction_id IS NULL` legs (exceeded-tolerance ICS bulk-settle
 * candidates per the Wave 1 schema rule) are SKIPPED in the walker —
 * the candidate row is still surfaced via `candidatesForReview()`,
 * where the user resolves it against the open card statement.
 *
 * Cross-user safety: every query filters on `user_id = $user->id`.
 * The root-transaction read raises NotFoundHttpException (404) when
 * the id belongs to another user, mirroring the `firstOrFail()`
 * pattern Categorization + Ledger Public actions use.
 *
 * Confidence-tier mapping (D-91):
 *   - `state='confirmed' AND resolver='auto' AND confidence=1.0` → `Deterministic`
 *   - `state='confirmed'` (any other resolver / confidence) → `Confirmed`
 *   - `state='candidate'` → `Candidate`
 */
final class ChainLinkQuery
{
    /**
     * Walk-depth ceiling. The project's payment topology is at most
     * five levels per D-92.
     */
    private const MAX_DEPTH = 5;

    /**
     * Same-signature confirmation count threshold for the
     * auto-promotion learning loop (D-87). Mirrored in
     * `ConfirmChainLink`; kept private here because the only consumer
     * is the `confirmsRemaining` derivation.
     */
    private const AUTO_PROMOTE_THRESHOLD = 3;

    public function __construct(private readonly DatabaseManager $db) {}

    public function forTransaction(int $transactionId, User $user): ChainTree
    {
        $rootRow = $this->db->connection()->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first([
                'id', 'counterparty_name',
                'amount_minor', 'currency',
                'settled_amount_minor', 'settled_currency',
                'booked_at', 'account_id',
            ]);

        if ($rootRow === null) {
            throw new NotFoundHttpException('Transaction not found.');
        }

        $rootId = self::toInt($rootRow->id);
        $nodes = [];
        $nodes[] = $this->makeNode($rootRow, null, 'root', 'Confirmed', $user);

        $frontier = [$rootId];
        $visited = [$rootId => true];
        $depth = 0;

        while ($frontier !== [] && $depth < self::MAX_DEPTH) {
            $links = $this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->whereIn('from_transaction_id', $frontier)
                ->whereIn('state', ['confirmed', 'candidate'])
                ->orderByDesc('confidence')
                ->get();

            $nextFrontier = [];
            foreach ($links as $link) {
                /** @var stdClass $link */
                // Issue #10 fix: exceeded-tolerance ICS bulk-settle
                // candidates carry to_transaction_id=NULL. Skip the
                // NULL leg in the walker; the candidate still
                // surfaces in candidatesForReview() for the user to
                // resolve.
                if ($link->to_transaction_id === null) {
                    continue;
                }

                $toId = self::toInt($link->to_transaction_id);
                if (isset($visited[$toId])) {
                    continue;
                }
                $visited[$toId] = true;
                $nextFrontier[] = $toId;

                $toRow = $this->db->connection()->table('transactions')
                    ->where('id', $toId)
                    ->where('user_id', $user->id)
                    ->first([
                        'id', 'counterparty_name',
                        'amount_minor', 'currency',
                        'settled_amount_minor', 'settled_currency',
                        'booked_at', 'account_id',
                    ]);
                if ($toRow === null) {
                    continue;
                }

                $confidenceTier = $this->confidenceTier(
                    self::toString($link->state),
                    self::toString($link->resolver),
                    self::toFloat($link->confidence ?? null),
                );

                $nodes[] = $this->makeNode(
                    $toRow,
                    self::toInt($link->id),
                    self::toString($link->kind),
                    $confidenceTier,
                    $user,
                );
            }

            $frontier = $nextFrontier;
            $depth++;
        }

        return new ChainTree(
            rootTransactionId: $rootId,
            nodes: $nodes,
        );
    }

    public function openCandidateCount(User $user): int
    {
        return self::toInt(
            $this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->where('state', 'candidate')
                ->count(),
        );
    }

    /**
     * Cursor-paginated review-queue rows. The cursor is the
     * `(confidence, id)` tuple of the previous page's last row;
     * lexicographic `(confidence, id) < (cursor)` keeps the sort
     * stable when multiple rows share the same confidence value.
     *
     * @return list<ChainLinkRow>
     */
    public function candidatesForReview(
        User $user,
        ?int $cursorId = null,
        ?string $cursorConfidence = null,
        int $limit = 26,
    ): array {
        $query = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', 'candidate')
            ->orderByDesc('confidence')
            ->orderByDesc('id')
            ->limit($limit);

        if ($cursorId !== null && $cursorConfidence !== null) {
            $query->where(function ($q) use ($cursorConfidence, $cursorId): void {
                /** @var Builder $q */
                $q->where('confidence', '<', $cursorConfidence)
                    ->orWhere(function ($qq) use ($cursorConfidence, $cursorId): void {
                        /** @var Builder $qq */
                        $qq->where('confidence', '=', $cursorConfidence)
                            ->where('id', '<', $cursorId);
                    });
            });
        }

        $rows = $query->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = $this->makeChainLinkRow($row, $user);
        }

        return $result;
    }

    /**
     * D-91 confidence-tier mapping.
     */
    private function confidenceTier(string $state, string $resolver, float $confidence): string
    {
        if ($state === 'confirmed' && $resolver === 'auto' && $confidence === 1.0) {
            return 'Deterministic';
        }
        if ($state === 'confirmed') {
            return 'Confirmed';
        }

        return 'Candidate';
    }

    private function makeNode(stdClass $row, ?int $chainLinkId, string $kind, string $tier, User $user): ChainTreeNode
    {
        $accountName = $this->resolveAccountName(self::toInt($row->account_id ?? null), $user);
        $currency = self::toString($row->settled_currency ?? null);
        if ($currency === '') {
            $currency = self::toString($row->currency ?? null);
        }
        if ($currency === '') {
            $currency = 'EUR';
        }
        $amountMinor = self::toInt($row->settled_amount_minor ?? $row->amount_minor ?? null);

        return new ChainTreeNode(
            transactionId: self::toInt($row->id),
            chainLinkId: $chainLinkId,
            counterpartyName: self::toString($row->counterparty_name ?? null),
            amount: Money::ofMinor($amountMinor, $currency),
            bookedAt: CarbonImmutable::parse(self::toString($row->booked_at ?? null)),
            accountName: $accountName,
            kind: $kind,
            confidenceTier: $tier,
            children: [],
        );
    }

    private function makeChainLinkRow(stdClass $row, User $user): ChainLinkRow
    {
        $evidence = json_decode(self::toString($row->evidence ?? null), true);
        $signatureHash = is_array($evidence) ? ($evidence['signature_hash'] ?? null) : null;

        $confirmsRemaining = self::AUTO_PROMOTE_THRESHOLD;
        if (is_string($signatureHash) && $signatureHash !== '') {
            $confirmedCount = self::toInt(
                $this->db->connection()->table('chain_links')
                    ->where('user_id', $user->id)
                    ->where('state', 'confirmed')
                    ->whereJsonContains('evidence->signature_hash', $signatureHash)
                    ->count(),
            );
            $confirmsRemaining = max(0, self::AUTO_PROMOTE_THRESHOLD - $confirmedCount);
        }

        $fromCounterparty = '';
        $fromAmountMinor = 0;
        $fromCurrency = 'EUR';
        $fromPostedAt = '1970-01-01';
        $fromRow = $this->db->connection()->table('transactions')
            ->where('id', $row->from_transaction_id)
            ->where('user_id', $user->id)
            ->first([
                'counterparty_name',
                'settled_amount_minor',
                'settled_currency',
                'posted_at',
            ]);
        if ($fromRow !== null) {
            $fromCounterparty = self::toString($fromRow->counterparty_name ?? null);
            $fromAmountMinor = self::toInt($fromRow->settled_amount_minor ?? null);
            $cur = self::toString($fromRow->settled_currency ?? null);
            $fromCurrency = $cur !== '' ? $cur : 'EUR';
            $fromPostedAt = self::toString($fromRow->posted_at ?? null);
            if ($fromPostedAt === '') {
                $fromPostedAt = '1970-01-01';
            }
        }

        $toCounterparty = '';
        $toAmountMinor = 0;
        $toCurrency = 'EUR';
        $toPostedAt = '1970-01-01';
        if ($row->to_transaction_id !== null) {
            $toRow = $this->db->connection()->table('transactions')
                ->where('id', $row->to_transaction_id)
                ->where('user_id', $user->id)
                ->first([
                    'counterparty_name',
                    'settled_amount_minor',
                    'settled_currency',
                    'posted_at',
                ]);
            if ($toRow !== null) {
                $toCounterparty = self::toString($toRow->counterparty_name ?? null);
                $toAmountMinor = self::toInt($toRow->settled_amount_minor ?? null);
                $cur = self::toString($toRow->settled_currency ?? null);
                $toCurrency = $cur !== '' ? $cur : 'EUR';
                $toPostedAt = self::toString($toRow->posted_at ?? null);
                if ($toPostedAt === '') {
                    $toPostedAt = '1970-01-01';
                }
            }
        }

        return new ChainLinkRow(
            chainLinkId: self::toInt($row->id),
            kind: self::toString($row->kind),
            state: self::toString($row->state),
            confidence: self::toFloat($row->confidence ?? null),
            fromCounterparty: $fromCounterparty,
            fromAmount: Money::ofMinor($fromAmountMinor, $fromCurrency),
            toCounterparty: $toCounterparty,
            toAmount: Money::ofMinor($toAmountMinor, $toCurrency),
            fromPostedAt: CarbonImmutable::parse($fromPostedAt),
            toPostedAt: CarbonImmutable::parse($toPostedAt),
            confirmsRemaining: $confirmsRemaining,
        );
    }

    private function resolveAccountName(int $accountId, User $user): string
    {
        if ($accountId === 0) {
            return '';
        }
        $row = $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->first(['name']);

        if ($row === null) {
            return '';
        }

        return self::toString($row->name);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
