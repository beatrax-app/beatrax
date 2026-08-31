<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Presentation;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\AutoPromotion;
use Modules\Chains\Public\Dto\ChainLinkHintRow;
use Modules\Chains\Public\Dto\ChainLinkRow;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

// The chain_links rows a screen was handed, turned into the two row shapes it
// renders. Both shapes read the same endpoint summaries and fall back to the
// same empty one for a transaction the reader does not own, so they are built
// here rather than beside the queries that fetched the rows.
final readonly class ChainLinkRowFactory
{
    use CoercesScalars;

    // A transaction the reader does not own has no date to show, and the row
    // still has to render: this is the date that stands in for one.
    private const string MISSING_POSTED_AT_SENTINEL = '1970-01-01';

    public function __construct(
        private DatabaseManager $db,
        private CounterpartyDisplay $counterparty,
        private HintEvidenceSummary $hintEvidence,
        private BaseCurrency $baseCurrency,
    ) {}

    // Every endpoint on the page is read once, and every distinct signature is
    // counted once: the per-row form spent three queries on each row, so a
    // full review page cost seventy-eight and a settlement card three hundred.
    /**
     * @param  list<stdClass>  $rows
     * @return list<ChainLinkRow>
     */
    public function chainLinkRows(array $rows, User $user): array
    {
        if ($rows === []) {
            return [];
        }

        $summaries = $this->transactionSummaries($this->endpointIds($rows), $user);
        /** @var array<string, int> $confirmsRemaining */
        $confirmsRemaining = [];

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->makeChainLinkRow($row, $user, $summaries, $confirmsRemaining);
        }

        return $result;
    }

    // The hint shape: one endpoint rather than two, and the account name beside
    // it, because a hint names a row the reader has to recognise before there
    // is anything to confirm.
    /**
     * @param  list<stdClass>  $rows
     * @return list<ChainLinkHintRow>
     */
    public function hintRows(array $rows, User $user): array
    {
        if ($rows === []) {
            return [];
        }

        $summaries = $this->transactionSummaries($this->endpointIds($rows), $user);
        $accountNames = $this->accountNames($user);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->makeChainLinkHintRow($row, $summaries, $accountNames);
        }

        return $result;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<int>
     */
    private function endpointIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ([$row->from_transaction_id ?? null, $row->to_transaction_id ?? null] as $endpoint) {
                $id = self::toInt($endpoint);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @param  array<int, array{counterparty: string, amountMinor: int, currency: string, postedAt: string, slug: ?string, accountId: int}>  $summaries
     * @param  array<string, int>  $confirmsRemaining
     *
     * @param-out array<string, int> $confirmsRemaining
     */
    private function makeChainLinkRow(stdClass $row, User $user, array $summaries, array &$confirmsRemaining): ChainLinkRow
    {
        $from = $summaries[self::toInt($row->from_transaction_id ?? null)] ?? $this->missingTransactionSummary();
        $to = $summaries[self::toInt($row->to_transaction_id ?? null)] ?? $this->missingTransactionSummary();

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
            confirmsRemaining: $this->confirmsRemaining($row, $user, $confirmsRemaining),
            fromCounterpartySlug: $from['slug'],
            toCounterpartySlug: $to['slug'],
        );
    }

    // Every leg of one settlement carries that statement's signature, so the
    // memo turns one count per row into one per distinct signature.
    /**
     * @param  array<string, int>  $memo
     *
     * @param-out array<string, int> $memo
     */
    private function confirmsRemaining(stdClass $row, User $user, array &$memo): int
    {
        $evidence = json_decode(self::toString($row->evidence ?? null), true);
        $signatureHash = is_array($evidence) ? ($evidence['signature_hash'] ?? null) : null;
        if (! is_string($signatureHash) || $signatureHash === '') {
            return AutoPromotion::THRESHOLD;
        }

        if (array_key_exists($signatureHash, $memo)) {
            return $memo[$signatureHash];
        }

        $confirmedCount = self::toInt(
            $this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->where('state', ChainLinkState::Confirmed->value)
                ->whereJsonContains('evidence->signature_hash', $signatureHash)
                ->count(),
        );

        return $memo[$signatureHash] = AutoPromotion::remaining($confirmedCount);
    }

    /**
     * @return array{counterparty: string, amountMinor: int, currency: string, postedAt: string, slug: ?string, accountId: int}
     */
    private function missingTransactionSummary(): array
    {
        return [
            'counterparty' => '',
            'amountMinor' => 0,
            'currency' => $this->baseCurrency->code(),
            'postedAt' => self::MISSING_POSTED_AT_SENTINEL,
            'slug' => null,
            'accountId' => 0,
        ];
    }

    // One read for every endpoint on the page. An id absent from the result is
    // one the reader does not own, and its side of the row renders empty.
    /**
     * @param  list<int>  $transactionIds
     * @return array<int, array{counterparty: string, amountMinor: int, currency: string, postedAt: string, slug: ?string, accountId: int}>
     */
    private function transactionSummaries(array $transactionIds, User $user): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = $this->db->connection()->table('transactions')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->whereIn('transactions.id', $transactionIds)
            ->where('transactions.user_id', $user->id)
            ->select([
                'transactions.id',
                'transactions.account_id',
                'transactions.counterparty_name',
                'transactions.settled_amount_minor',
                'transactions.settled_currency',
                'transactions.posted_at',
                CounterpartyDisplay::SLUG_SELECT,
            ])
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $currency = self::toString($row->settled_currency ?? null);
            $postedAt = self::toString($row->posted_at ?? null);
            $summaries[self::toInt($row->id)] = [
                'counterparty' => $this->counterparty->name(self::toString($row->counterparty_name ?? null), $user->id),
                'amountMinor' => self::toInt($row->settled_amount_minor ?? null),
                'currency' => $currency !== '' ? $currency : $this->baseCurrency->code(),
                'postedAt' => $postedAt !== '' ? $postedAt : self::MISSING_POSTED_AT_SENTINEL,
                'slug' => $this->counterparty->slug($row),
                'accountId' => self::toInt($row->account_id ?? null),
            ];
        }

        return $summaries;
    }

    /**
     * @return array<int, string> keyed by account id; an id absent here is one the reader does not own
     */
    private function accountNames(User $user): array
    {
        $names = [];
        foreach ($this->db->connection()->table('accounts')->where('user_id', $user->id)->get(['id', 'name']) as $row) {
            /** @var stdClass $row */
            $names[self::toInt($row->id)] = self::toString($row->name);
        }

        return $names;
    }

    /**
     * @param  array<int, array{counterparty: string, amountMinor: int, currency: string, postedAt: string, slug: ?string, accountId: int}>  $summaries
     * @param  array<int, string>  $accountNames
     */
    private function makeChainLinkHintRow(stdClass $row, array $summaries, array $accountNames): ChainLinkHintRow
    {
        $fromTxId = self::toInt($row->from_transaction_id ?? null);
        $from = $summaries[$fromTxId] ?? $this->missingTransactionSummary();

        $evidenceLines = $this->hintEvidence->forHint(
            self::toString($row->kind ?? null),
            self::toString($row->evidence ?? null),
            $from['currency'],
        );

        return new ChainLinkHintRow(
            chainLinkId: self::toInt($row->id),
            kind: self::toString($row->kind ?? null),
            confidence: self::toFloat($row->confidence ?? null),
            fromTransactionId: $fromTxId,
            fromCounterparty: $from['counterparty'],
            fromAmount: Money::ofMinor($from['amountMinor'], $from['currency']),
            fromPostedAt: CarbonImmutable::parse($from['postedAt']),
            fromAccountName: $accountNames[$from['accountId']] ?? '',
            evidenceLines: $evidenceLines,
            fromCounterpartySlug: $from['slug'],
        );
    }
}
