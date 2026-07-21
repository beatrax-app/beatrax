<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Events\AnomalyAlertDismissed;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final class DismissAnomalyAlertAsExpected
{
    private const BAND_LOW_MULTIPLIER = 0.85;

    private const BAND_HIGH_MULTIPLIER = 1.15;

    public function __construct(
        private readonly AnomalyAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
    ) {}

    // Returns TRUE when at least one suppression rule was written, FALSE
    // when none was — so the caller's UI can avoid promising "Suppression
    // rule added" when nothing was muted. An already-dismissed alert is a
    // no-op and returns FALSE.
    public function __invoke(int $alertId, User $user): bool
    {
        /** @var AnomalyAlert|null $alert */
        $alert = AnomalyAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Anomaly alert not found.');
        }

        if ($alert->state === 'dismissed') {
            return false;
        }

        $now = $this->clock->now();

        $this->stateMachine->transition(
            $alert,
            'dismissed',
            'user_dismissed_expected',
            'user',
            null,
            ['dismissed_as' => 'expected', 'actioned_at' => $now->toDateTimeString()],
        );

        $rulesWritten = $this->insertSuppressionRules($alert, $user, $now->toDateTimeString());

        $this->events->dispatch(new AnomalyAlertDismissed(
            userId: $user->id,
            anomalyAlertId: $alertId,
            dismissedAs: 'expected',
        ));

        return $rulesWritten;
    }

    // The band is derived from the persisted alert, never the client. A
    // null counterparty falls back to a normalized-name rule
    // (counterparty_id IS NULL) which the evaluator still matches.
    private function insertSuppressionRules(AnomalyAlert $alert, User $user, string $nowString): bool
    {
        $reasons = $alert->reasons;
        if ($reasons === []) {
            return false;
        }

        $currency = is_string($alert->currency) && $alert->currency !== '' ? $alert->currency : null;

        $latestMinor = $alert->latest_amount_minor;
        if ($latestMinor === null) {
            // A duplicate-only / first-time-only alert carries no
            // per-merchant baseline. Fall back to the alert's own
            // transaction settled amount so a band is still written and
            // that detector still gets suppression.
            $settled = $this->settledChargeForTransaction($user, $alert->transaction_id);
            if ($settled === null) {
                return false;
            }
            $latestMinor = $settled['amount_minor'];
            $currency ??= $settled['currency'];
        }

        $currency ??= 'EUR';

        $boundA = (int) round(self::BAND_LOW_MULTIPLIER * $latestMinor);
        $boundB = (int) round(self::BAND_HIGH_MULTIPLIER * $latestMinor);
        // Signed amounts: for an expense (negative) the 1.15x bound is the
        // more-negative one. Normalise to [low, high] so the evaluator's
        // band_low <= settled <= band_high check holds regardless of sign.
        $bandLow = min($boundA, $boundB);
        $bandHigh = max($boundA, $boundB);

        $counterpartyId = $this->counterpartyIdForTransaction($user, $alert->transaction_id);

        // Make re-dismissal idempotent: insert each reason only when no
        // rule with the identical natural key already exists (a plain
        // UNIQUE index would treat NULL counterparties as distinct and
        // not dedupe them).
        $connection = $this->db->connection();
        $wrote = false;

        foreach ($reasons as $reason) {
            $exists = $connection->table('anomaly_suppression_rules')
                ->where('user_id', $user->id)
                ->where('detector', $reason)
                ->where('direction', $alert->direction)
                ->where('amount_band_low_minor', $bandLow)
                ->where('amount_band_high_minor', $bandHigh)
                ->where('currency', $currency)
                ->when(
                    $counterpartyId === null,
                    static fn (Builder $q) => $q->whereNull('counterparty_id'),
                    static fn (Builder $q) => $q->where('counterparty_id', $counterpartyId),
                )
                ->exists();

            if ($exists) {
                continue;
            }

            $connection->table('anomaly_suppression_rules')->insert([
                'user_id' => $user->id,
                'counterparty_id' => $counterpartyId,
                'detector' => $reason,
                'direction' => $alert->direction,
                'amount_band_low_minor' => $bandLow,
                'amount_band_high_minor' => $bandHigh,
                'currency' => $currency,
                'source_anomaly_alert_id' => $alert->id,
                'created_at' => $nowString,
                'updated_at' => $nowString,
            ]);
            $wrote = true;
        }

        return $wrote;
    }

    // A permitted ledger READ, mirroring counterpartyIdForTransaction.
    // Returns null when the transaction no longer exists.
    /**
     * @return array{amount_minor: int, currency: string}|null
     */
    private function settledChargeForTransaction(User $user, int $transactionId): ?array
    {
        $row = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', $transactionId)
            ->first(['settled_amount_minor', 'settled_currency']);

        if ($row === null) {
            return null;
        }

        $amount = $row->settled_amount_minor ?? null;
        if (! is_numeric($amount)) {
            return null;
        }

        $currency = is_string($row->settled_currency ?? null) && $row->settled_currency !== ''
            ? $row->settled_currency
            : 'EUR';

        return ['amount_minor' => (int) $amount, 'currency' => $currency];
    }

    // A permitted ledger READ (the anomaly table keys per-transaction).
    // Returns null for an unresolved merchant, producing a
    // normalized-name-fallback rule.
    private function counterpartyIdForTransaction(User $user, int $transactionId): ?int
    {
        $row = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', $transactionId)
            ->first(['counterparty_id']);

        if ($row === null) {
            return null;
        }

        $cpId = $row->counterparty_id ?? null;

        return is_numeric($cpId) && (int) $cpId > 0 ? (int) $cpId : null;
    }
}
