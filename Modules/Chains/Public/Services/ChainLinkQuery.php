<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Chains\Public\Actions\DismissChainLinkHint;
use Modules\Chains\Public\Dto\ChainLinkHintRow;
use Modules\Chains\Public\Dto\ChainLinkRow;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\ChainTreeNode;
use Modules\Chains\Public\Dto\SeriesFunderLink;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/chains/architecture.md
 */
final class ChainLinkQuery
{
    private const MAX_DEPTH = 5;

    // Mirrored in ConfirmChainLink; kept private here because the only
    // consumer is the confirmsRemaining derivation.
    private const AUTO_PROMOTE_THRESHOLD = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    public function forTransaction(int $transactionId, User $user): ChainTree
    {
        $rootRow = $this->fetchTransactionDisplayRow($transactionId, $user);

        if ($rootRow === null) {
            throw new NotFoundHttpException('Transaction not found.');
        }

        $rootId = self::toInt($rootRow->id);
        $nodes = [];
        $nodes[] = $this->makeNode($rootRow, null, 'root', 'Confirmed', $user);

        $frontier = [$rootId];
        $visited = [$rootId => true];
        $depth = 0;

        // Walks chain_links in both directions from the root transaction
        // — see architecture.md § ChainLinkQuery for why forward-only
        // missed half of paypal_funding's click-through cases.
        while ($frontier !== [] && $depth < self::MAX_DEPTH) {
            $links = $this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->where(function (Builder $q) use ($frontier): void {
                    $q->whereIn('from_transaction_id', $frontier)
                        ->orWhereIn('to_transaction_id', $frontier);
                })
                ->whereIn('state', ['confirmed', 'candidate'])
                ->orderByDesc('confidence')
                ->get();

            $nextFrontier = [];
            foreach ($links as $link) {
                /** @var stdClass $link */
                // NULL to_transaction_id legs (exceeded-tolerance
                // candidates) surface via hintsForReview() instead.
                if ($link->to_transaction_id === null) {
                    continue;
                }

                $fromId = self::toInt($link->from_transaction_id);
                $toId = self::toInt($link->to_transaction_id);
                // The partner is the OTHER side of the link relative to
                // the current frontier: to when from is on the frontier
                // (forward walk), from otherwise (backward walk).
                $partnerId = isset($visited[$fromId]) ? $toId : $fromId;
                if (isset($visited[$partnerId])) {
                    continue;
                }
                $visited[$partnerId] = true;
                $nextFrontier[] = $partnerId;

                $partnerRow = $this->fetchTransactionDisplayRow($partnerId, $user);
                if ($partnerRow === null) {
                    continue;
                }

                $confidenceTier = $this->confidenceTier(
                    self::toString($link->state),
                    self::toString($link->resolver),
                    self::toFloat($link->confidence ?? null),
                );

                $nodes[] = $this->makeNode(
                    $partnerRow,
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

    /**
     * @return list<ChainLinkRow>
     */
    public function allChainsForUser(User $user, int $limit = 50): array
    {
        $rows = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->whereIn('state', ['confirmed', 'candidate'])
            ->whereNotNull('to_transaction_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = $this->makeChainLinkRow($row, $user);
        }

        return $result;
    }

    // Gates the transaction-detail page's "View chain" trigger so it only
    // renders for rows that actually have something behind the click.
    public function hasChainForTransaction(int $transactionId, User $user): bool
    {
        return $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->whereIn('state', ['confirmed', 'candidate'])
            ->where(function (Builder $q) use ($transactionId): void {
                $q->where('from_transaction_id', $transactionId)
                    ->orWhere('to_transaction_id', $transactionId);
            })
            ->exists();
    }

    // Used by the Forecasting module's chain-aware router to rewrite
    // contribution account ids onto the funder accounts. An empty result
    // means "no chain resolution" — the contribution stays on the series's
    // own account.
    /**
     * @return list<SeriesFunderLink>
     */
    public function confirmedAndDeterministicForSeries(int $seriesId, User $user): array
    {
        $rows = $this->db->connection()->table('chain_links')
            ->join('recurring_series_occurrences as rso', 'rso.transaction_id', '=', 'chain_links.from_transaction_id')
            ->join('transactions as funder_tx', 'funder_tx.id', '=', 'chain_links.to_transaction_id')
            ->where('chain_links.user_id', $user->id)
            ->where('rso.recurring_series_id', $seriesId)
            ->where(function ($q): void {
                /** @var Builder $q */
                $q->where('chain_links.state', 'confirmed')
                    ->orWhere('chain_links.resolver', 'auto');
            })
            ->whereNotNull('chain_links.to_transaction_id')
            ->select(
                'chain_links.id as chain_link_id',
                'chain_links.from_transaction_id as from_transaction_id',
                'chain_links.to_transaction_id as to_transaction_id',
                'funder_tx.account_id as funder_account_id',
                'chain_links.kind as kind',
                'chain_links.state as state',
                'chain_links.resolver as resolver',
                'chain_links.confidence as confidence',
            )
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = new SeriesFunderLink(
                chainLinkId: self::toInt($row->chain_link_id),
                fromTransactionId: self::toInt($row->from_transaction_id),
                toTransactionId: self::toInt($row->to_transaction_id),
                funderAccountId: self::toInt($row->funder_account_id),
                kind: self::toString($row->kind),
                state: self::toString($row->state),
                resolver: self::toString($row->resolver),
                confidence: self::toFloat($row->confidence),
            );
        }

        return $result;
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

    // The cursor is the (confidence, id) tuple of the previous page's last
    // row; lexicographic (confidence, id) < (cursor) keeps the sort stable
    // across ties on confidence.
    /**
     * @return list<ChainLinkRow>
     */
    public function candidatesForReview(
        User $user,
        ?int $cursorId = null,
        ?string $cursorConfidence = null,
        int $limit = 26,
    ): array {
        // Hint-shaped rows carry to_transaction_id IS NULL and cannot be
        // confirmed/rejected via the queue's buttons — filtered out here
        // so the review queue only surfaces actionable rows.
        $query = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', 'candidate')
            ->whereNotNull('to_transaction_id')
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

    // The complement of candidatesForReview() — every candidate row with
    // to_transaction_id IS NULL, dismissable only via DismissChainLinkHint.
    // Sorted newest-first so a fresh scan surfaces at the top to triage.
    /**
     * @return list<ChainLinkHintRow>
     */
    public function hintsForReview(User $user): array
    {
        $rows = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', 'candidate')
            ->whereNull('to_transaction_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = $this->makeChainLinkHintRow($row, $user);
        }

        return $result;
    }

    // Separate from hintsForReview() so a badge can show the count
    // without paying the per-row from-transaction lookup cost.
    public function hintCount(User $user): int
    {
        return $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', 'candidate')
            ->whereNull('to_transaction_id')
            ->count();
    }

    // Reads through SensitiveColumnCodec; a pass-through no-op when
    // encryption is not enabled for this user.
    private function decryptCounterpartyName(?string $raw, int $userId): string
    {
        $stored = $raw ?? '';
        if ($stored === '') {
            return '';
        }

        return $this->codec->decryptValue('transactions', 'counterparty_name', $stored, $userId, $this->session)['value'];
    }

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
        $counterpartySlug = self::extractCounterpartySlug($row);

        return new ChainTreeNode(
            transactionId: self::toInt($row->id),
            chainLinkId: $chainLinkId,
            counterpartyName: $this->decryptCounterpartyName(self::toString($row->counterparty_name ?? null), $user->id),
            amount: Money::ofMinor($amountMinor, $currency),
            bookedAt: CarbonImmutable::parse(self::toString($row->booked_at ?? null)),
            accountName: $accountName,
            kind: $kind,
            confidenceTier: $tier,
            children: [],
            counterpartySlug: $counterpartySlug,
        );
    }

    // Joins counterparties so the resolved slug travels alongside the row
    // data, keeping the drawer render path free of N+1 lookups.
    private function fetchTransactionDisplayRow(int $transactionId, User $user): ?stdClass
    {
        $row = $this->db->connection()->table('transactions')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->where('transactions.id', $transactionId)
            ->where('transactions.user_id', $user->id)
            ->select([
                'transactions.id',
                'transactions.counterparty_name',
                'transactions.amount_minor',
                'transactions.currency',
                'transactions.settled_amount_minor',
                'transactions.settled_currency',
                'transactions.booked_at',
                'transactions.account_id',
                'counterparties.slug as counterparty_slug',
            ])
            ->first();

        return $row instanceof stdClass ? $row : null;
    }

    // An empty slug or a missing column both collapse to null so the
    // consumer falls back to plain-text rendering instead of a dead-end URL.
    private static function extractCounterpartySlug(stdClass $row): ?string
    {
        if (! property_exists($row, 'counterparty_slug') || $row->counterparty_slug === null) {
            return null;
        }
        $slug = self::toString($row->counterparty_slug);

        return $slug === '' ? null : $slug;
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
        $fromCounterpartySlug = null;
        $fromRow = $this->db->connection()->table('transactions')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->where('transactions.id', $row->from_transaction_id)
            ->where('transactions.user_id', $user->id)
            ->select([
                'transactions.counterparty_name',
                'transactions.settled_amount_minor',
                'transactions.settled_currency',
                'transactions.posted_at',
                'counterparties.slug as counterparty_slug',
            ])
            ->first();
        if ($fromRow !== null) {
            $fromCounterparty = $this->decryptCounterpartyName(self::toString($fromRow->counterparty_name ?? null), $user->id);
            $fromAmountMinor = self::toInt($fromRow->settled_amount_minor ?? null);
            $cur = self::toString($fromRow->settled_currency ?? null);
            $fromCurrency = $cur !== '' ? $cur : 'EUR';
            $fromPostedAt = self::toString($fromRow->posted_at ?? null);
            if ($fromPostedAt === '') {
                $fromPostedAt = '1970-01-01';
            }
            $fromCounterpartySlug = self::extractCounterpartySlug($fromRow);
        }

        $toCounterparty = '';
        $toAmountMinor = 0;
        $toCurrency = 'EUR';
        $toPostedAt = '1970-01-01';
        $toCounterpartySlug = null;
        if ($row->to_transaction_id !== null) {
            $toRow = $this->db->connection()->table('transactions')
                ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
                ->where('transactions.id', $row->to_transaction_id)
                ->where('transactions.user_id', $user->id)
                ->select([
                    'transactions.counterparty_name',
                    'transactions.settled_amount_minor',
                    'transactions.settled_currency',
                    'transactions.posted_at',
                    'counterparties.slug as counterparty_slug',
                ])
                ->first();
            if ($toRow !== null) {
                $toCounterparty = $this->decryptCounterpartyName(self::toString($toRow->counterparty_name ?? null), $user->id);
                $toAmountMinor = self::toInt($toRow->settled_amount_minor ?? null);
                $cur = self::toString($toRow->settled_currency ?? null);
                $toCurrency = $cur !== '' ? $cur : 'EUR';
                $toPostedAt = self::toString($toRow->posted_at ?? null);
                if ($toPostedAt === '') {
                    $toPostedAt = '1970-01-01';
                }
                $toCounterpartySlug = self::extractCounterpartySlug($toRow);
            }
        }

        return new ChainLinkRow(
            chainLinkId: self::toInt($row->id),
            kind: self::toString($row->kind),
            state: self::toString($row->state),
            confidence: self::toFloat($row->confidence ?? null),
            fromTransactionId: self::toInt($row->from_transaction_id ?? null),
            fromCounterparty: $fromCounterparty,
            fromAmount: Money::ofMinor($fromAmountMinor, $fromCurrency),
            toTransactionId: self::toInt($row->to_transaction_id ?? null),
            toCounterparty: $toCounterparty,
            toAmount: Money::ofMinor($toAmountMinor, $toCurrency),
            fromPostedAt: CarbonImmutable::parse($fromPostedAt),
            toPostedAt: CarbonImmutable::parse($toPostedAt),
            confirmsRemaining: $confirmsRemaining,
            fromCounterpartySlug: $fromCounterpartySlug,
            toCounterpartySlug: $toCounterpartySlug,
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

    private function makeChainLinkHintRow(stdClass $row, User $user): ChainLinkHintRow
    {
        $fromTxId = self::toInt($row->from_transaction_id ?? null);
        $fromCounterparty = '';
        $fromAmountMinor = 0;
        $fromCurrency = 'EUR';
        $fromPostedAt = '1970-01-01';
        $fromAccountId = 0;
        $fromCounterpartySlug = null;
        $fromRow = $this->db->connection()->table('transactions')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->where('transactions.id', $fromTxId)
            ->where('transactions.user_id', $user->id)
            ->select([
                'transactions.account_id',
                'transactions.counterparty_name',
                'transactions.settled_amount_minor',
                'transactions.settled_currency',
                'transactions.posted_at',
                'counterparties.slug as counterparty_slug',
            ])
            ->first();
        if ($fromRow !== null) {
            $fromAccountId = self::toInt($fromRow->account_id ?? null);
            $fromCounterparty = $this->decryptCounterpartyName(self::toString($fromRow->counterparty_name ?? null), $user->id);
            $fromAmountMinor = self::toInt($fromRow->settled_amount_minor ?? null);
            $cur = self::toString($fromRow->settled_currency ?? null);
            $fromCurrency = $cur !== '' ? $cur : 'EUR';
            $fromPostedAt = self::toString($fromRow->posted_at ?? null);
            if ($fromPostedAt === '') {
                $fromPostedAt = '1970-01-01';
            }
            $fromCounterpartySlug = self::extractCounterpartySlug($fromRow);
        }

        $evidenceLines = $this->summariseHintEvidence(
            self::toString($row->kind ?? null),
            self::toString($row->evidence ?? null),
            $fromCurrency,
        );

        return new ChainLinkHintRow(
            chainLinkId: self::toInt($row->id),
            kind: self::toString($row->kind ?? null),
            confidence: self::toFloat($row->confidence ?? null),
            fromTransactionId: $fromTxId,
            fromCounterparty: $fromCounterparty,
            fromAmount: Money::ofMinor($fromAmountMinor, $fromCurrency),
            fromPostedAt: CarbonImmutable::parse($fromPostedAt),
            fromAccountName: $this->resolveAccountName($fromAccountId, $user),
            evidenceLines: $evidenceLines,
            fromCounterpartySlug: $fromCounterpartySlug,
        );
    }

    /**
     * @return list<string>
     */
    private function summariseHintEvidence(string $kind, string $evidenceJson, string $currency): array
    {
        if ($evidenceJson === '') {
            return [];
        }
        $evidence = json_decode($evidenceJson, true);
        if (! is_array($evidence)) {
            return [];
        }

        $lines = [];

        if ($kind === 'ics_bulk_settle') {
            $tolerance = $evidence['tolerance_used'] ?? null;
            if (is_string($tolerance) && $tolerance !== '') {
                $lines[] = 'Tolerance: '.$tolerance;
            }
            $delta = $evidence['unaccounted_delta_minor'] ?? null;
            if (is_numeric($delta)) {
                $deltaInt = (int) $delta;
                $lines[] = 'Unaccounted delta: '.Money::ofMinor($deltaInt, $currency)->format('en_US');
            }
            $covered = $evidence['covered_count'] ?? null;
            if (is_numeric($covered)) {
                $lines[] = 'Covered transactions: '.(int) $covered;
            }
            $stmt = $evidence['statement_id'] ?? null;
            if (is_numeric($stmt)) {
                $lines[] = 'Card statement #'.(int) $stmt;
            }
        } elseif ($kind === 'funded_by_card_hint') {
            $last4 = $evidence['card_last_four'] ?? null;
            if (is_string($last4) && $last4 !== '') {
                $lines[] = 'Card ending in '.$last4;
            }
        } elseif ($kind === 'refund_of_hint') {
            $ref = $evidence['original_reference_id'] ?? null;
            if (is_string($ref) && $ref !== '') {
                $lines[] = 'Original order ref: '.$ref;
            }
        }

        return $lines;
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
