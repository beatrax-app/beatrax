<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Chains\Internal\ChainTreeWalker;
use Modules\Chains\Internal\Presentation\HintEvidenceSummary;
use Modules\Chains\Public\Dto\ChainLinkHintRow;
use Modules\Chains\Public\Dto\ChainLinkRow;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\SeriesFunderLink;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final class ChainLinkQuery
{
    use CoercesScalars;

    private const COUNTERPARTY_SLUG = 'counterparties.slug as counterparty_slug';

    private const MISSING_POSTED_AT_SENTINEL = '1970-01-01';

    // ConfirmChainLink holds this same threshold and does the promoting; if the
    // two drift, the countdown shown to the user stops matching when it fires.
    private const AUTO_PROMOTE_THRESHOLD = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        // A factory, not the session: resolving one builds the encrypter, and
        // Artisan constructs this class merely to list the console command.
        private readonly SessionFactory $session,
        private readonly HintEvidenceSummary $hintEvidence,
        private readonly ChainTreeWalker $treeWalker,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    public function forTransaction(int $transactionId, User $user): ChainTree
    {
        return $this->treeWalker->walk($transactionId, $user);
    }

    /**
     * @return list<ChainLinkRow>
     */
    public function allChainsForUser(User $user, int $limit = 50): array
    {
        $rows = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->whereIn('state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value])
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

    public function hasChainForTransaction(int $transactionId, User $user): bool
    {
        return $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->whereIn('state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value])
            ->where(function (Builder $q) use ($transactionId): void {
                $q->where('from_transaction_id', $transactionId)
                    ->orWhere('to_transaction_id', $transactionId);
            })
            ->exists();
    }

    // Forecasting's chain-aware router rewrites contribution account ids onto
    // these funders; an empty result leaves the contribution where it is.
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
                $q->where('chain_links.state', ChainLinkState::Confirmed->value)
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
                ->where('state', ChainLinkState::Candidate->value)
                ->count(),
        );
    }

    // Keyset cursor: the (confidence, id) tuple of the previous page's last
    // row, compared lexicographically so ties on confidence stay stable.
    /**
     * @return list<ChainLinkRow>
     */
    public function candidatesForReview(
        User $user,
        ?int $cursorId = null,
        ?string $cursorConfidence = null,
        int $limit = 26,
    ): array {
        // Hint-shaped rows (to_transaction_id IS NULL) have no confirm/reject
        // path, so the queue filters them out as unactionable.
        $query = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', ChainLinkState::Candidate->value)
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

    /**
     * @return list<ChainLinkHintRow>
     */
    public function hintsForReview(User $user): array
    {
        $rows = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', ChainLinkState::Candidate->value)
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

    public function hintCount(User $user): int
    {
        return $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', ChainLinkState::Candidate->value)
            ->whereNull('to_transaction_id')
            ->count();
    }

    private function decryptCounterpartyName(?string $raw, int $userId): string
    {
        $stored = $raw ?? '';
        if ($stored === '') {
            return '';
        }

        return $this->codec->decryptValue('transactions', 'counterparty_name', $stored, $userId, ($this->session)())['value'];
    }

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
        $from = $this->transactionSummary($row->from_transaction_id ?? null, $user);
        $to = $this->transactionSummary($row->to_transaction_id ?? null, $user);

        return new ChainLinkRow(
            chainLinkId: self::toInt($row->id),
            kind: self::toString($row->kind),
            state: self::toString($row->state),
            confidence: self::toFloat($row->confidence ?? null),
            fromTransactionId: self::toInt($row->from_transaction_id ?? null),
            fromCounterparty: $from['counterparty'],
            fromAmount: Money::ofMinor($from['amountMinor'], $from['currency']),
            toTransactionId: self::toInt($row->to_transaction_id ?? null),
            toCounterparty: $to['counterparty'],
            toAmount: Money::ofMinor($to['amountMinor'], $to['currency']),
            fromPostedAt: CarbonImmutable::parse($from['postedAt']),
            toPostedAt: CarbonImmutable::parse($to['postedAt']),
            confirmsRemaining: $this->confirmsRemaining($row, $user),
            fromCounterpartySlug: $from['slug'],
            toCounterpartySlug: $to['slug'],
        );
    }

    private function confirmsRemaining(stdClass $row, User $user): int
    {
        $evidence = json_decode(self::toString($row->evidence ?? null), true);
        $signatureHash = is_array($evidence) ? ($evidence['signature_hash'] ?? null) : null;
        if (! is_string($signatureHash) || $signatureHash === '') {
            return self::AUTO_PROMOTE_THRESHOLD;
        }

        $confirmedCount = self::toInt(
            $this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->where('state', ChainLinkState::Confirmed->value)
                ->whereJsonContains('evidence->signature_hash', $signatureHash)
                ->count(),
        );

        return max(0, self::AUTO_PROMOTE_THRESHOLD - $confirmedCount);
    }

    /**
     * @return array{counterparty: string, amountMinor: int, currency: string, postedAt: string, slug: ?string}
     */
    private function transactionSummary(mixed $transactionId, User $user): array
    {
        $default = ['counterparty' => '', 'amountMinor' => 0, 'currency' => $this->baseCurrency->code(), 'postedAt' => self::MISSING_POSTED_AT_SENTINEL, 'slug' => null];
        if ($transactionId === null) {
            return $default;
        }

        $row = $this->db->connection()->table('transactions')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->where('transactions.id', $transactionId)
            ->where('transactions.user_id', $user->id)
            ->select([
                'transactions.counterparty_name',
                'transactions.settled_amount_minor',
                'transactions.settled_currency',
                'transactions.posted_at',
                self::COUNTERPARTY_SLUG,
            ])
            ->first();
        if ($row === null) {
            return $default;
        }

        $currency = self::toString($row->settled_currency ?? null);
        $postedAt = self::toString($row->posted_at ?? null);

        return [
            'counterparty' => $this->decryptCounterpartyName(self::toString($row->counterparty_name ?? null), $user->id),
            'amountMinor' => self::toInt($row->settled_amount_minor ?? null),
            'currency' => $currency !== '' ? $currency : $this->baseCurrency->code(),
            'postedAt' => $postedAt !== '' ? $postedAt : self::MISSING_POSTED_AT_SENTINEL,
            'slug' => self::extractCounterpartySlug($row),
        ];
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
        $fromCurrency = $this->baseCurrency->code();
        $fromPostedAt = self::MISSING_POSTED_AT_SENTINEL;
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
                self::COUNTERPARTY_SLUG,
            ])
            ->first();
        if ($fromRow !== null) {
            $fromAccountId = self::toInt($fromRow->account_id ?? null);
            $fromCounterparty = $this->decryptCounterpartyName(self::toString($fromRow->counterparty_name ?? null), $user->id);
            $fromAmountMinor = self::toInt($fromRow->settled_amount_minor ?? null);
            $cur = self::toString($fromRow->settled_currency ?? null);
            $fromCurrency = $cur !== '' ? $cur : $this->baseCurrency->code();
            $fromPostedAt = self::toString($fromRow->posted_at ?? null);
            if ($fromPostedAt === '') {
                $fromPostedAt = self::MISSING_POSTED_AT_SENTINEL;
            }
            $fromCounterpartySlug = self::extractCounterpartySlug($fromRow);
        }

        $evidenceLines = $this->hintEvidence->forHint(
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

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
