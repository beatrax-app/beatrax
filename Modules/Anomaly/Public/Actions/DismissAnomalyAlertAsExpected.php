<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Events\AnomalyAlertDismissed;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Dismisses an anomaly_alerts row "as expected" (D-17): the user confirms
 * the unusual charge is normal for this merchant. The row transitions to
 * `dismissed` with `dismissed_as = 'expected'`, AND one
 * anomaly_suppression_rules row is inserted per tripped detector reason so
 * a near-identical future charge from the same merchant is not re-flagged.
 *
 * The amount band is computed SERVER-SIDE as ±15% of the persisted
 * alert's `latest_amount_minor` (`round(0.85x)` / `round(1.15x)`, T-09-11)
 * — never read from the client payload. For a duplicate-only or
 * first-time-only alert (which carry no per-merchant `latest_amount_minor`),
 * the band falls back to the alert transaction's own settled amount
 * (CR-01), so D-17 suppression still works for those detectors. The band is
 * stored as [low, high] (the more-negative bound is `low`) in the alert's
 * settled currency so it matches the evaluator's
 * `band_low <= settled <= band_high` test for signed expense amounts.
 *
 * Provenance: each rule carries `source_anomaly_alert_id = $alertId` so
 * the undo path (`RemoveAnomalySuppressionRule::undoSuppression`) can find
 * and delete exactly the rules this dismissal created.
 *
 * Idempotent when already dismissed (no second transition, no duplicate
 * rules). Cross-user invocation raises NotFoundHttpException. Dispatches
 * `AnomalyAlertDismissed` with the `expected` discriminator.
 */
final class DismissAnomalyAlertAsExpected
{
    /** Server-side ±15% band multipliers (D-17). */
    private const BAND_LOW_MULTIPLIER = 0.85;

    private const BAND_HIGH_MULTIPLIER = 1.15;

    public function __construct(
        private readonly AnomalyAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Dismisses the alert "as expected" and writes the per-reason
     * suppression rules. Returns TRUE when at least one suppression rule
     * was written, FALSE when none was (e.g. an empty reason set, or no
     * resolvable charge amount to build a band from) — so the caller's UI
     * can avoid promising "Suppression rule added" when nothing was muted
     * (CR-01). An already-dismissed alert is a no-op and returns FALSE.
     */
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

    /**
     * Inserts one suppression rule per tripped detector reason. The band
     * is derived from the persisted alert (`latest_amount_minor`), never
     * the client. The merchant key (`counterparty_id`) is read from the
     * alert's transaction; a null counterparty falls back to a
     * normalized-name rule (counterparty_id IS NULL) which the evaluator
     * still matches.
     *
     * Returns TRUE when rules were written, FALSE otherwise.
     */
    private function insertSuppressionRules(AnomalyAlert $alert, User $user, string $nowString): bool
    {
        $reasons = $alert->reasons;
        if ($reasons === []) {
            return false;
        }

        // The currency the band is stored in. For a large-vs-typical alert
        // this is the persisted alert currency; for a duplicate-only /
        // first-time-only fallback it comes from the transaction's settled
        // currency (resolved below) so the band matches the evaluator's
        // settled_currency suppression test.
        $currency = is_string($alert->currency) && $alert->currency !== '' ? $alert->currency : null;

        $latestMinor = $alert->latest_amount_minor;
        if ($latestMinor === null) {
            // CR-01: a duplicate-only / first-time-only alert never carries
            // a per-merchant amount baseline (only LargeVsTypical populates
            // latest_amount_minor). Without a fallback the dismissal would
            // write NO suppression rule and the next identical duplicate /
            // first-time charge re-fires — silently defeating D-17. Fall
            // back to the alert's OWN transaction settled amount (the same
            // value the evaluator matches its band against), read via the
            // same permitted ledger READ used for counterparty resolution,
            // so a band IS written for those detectors.
            $settled = $this->settledChargeForTransaction($user, $alert->transaction_id);
            if ($settled === null) {
                // The transaction is gone (cascade-deleted) — there is no
                // amount to build a band from. The dismissal still stands;
                // no rule is written.
                return false;
            }
            $latestMinor = $settled['amount_minor'];
            // Prefer the alert's own currency when present; otherwise adopt
            // the transaction's settled currency for the fallback band.
            $currency ??= $settled['currency'];
        }

        // Final currency resolution: a large-vs-typical alert always carries
        // its currency; the fallback path set it above. Default to EUR only
        // if both are somehow absent.
        $currency ??= 'EUR';

        $boundA = (int) round(self::BAND_LOW_MULTIPLIER * $latestMinor);
        $boundB = (int) round(self::BAND_HIGH_MULTIPLIER * $latestMinor);
        // Signed amounts: for an expense (negative) the 1.15x bound is the
        // more-negative one. Normalise to [low, high] so the evaluator's
        // band_low <= settled <= band_high check holds regardless of sign.
        $bandLow = min($boundA, $boundB);
        $bandHigh = max($boundA, $boundB);

        $counterpartyId = $this->counterpartyIdForTransaction($user, $alert->transaction_id);

        $rows = [];
        foreach ($reasons as $reason) {
            $rows[] = [
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
            ];
        }

        $this->db->connection()->table('anomaly_suppression_rules')->insert($rows);

        return true;
    }

    /**
     * Resolve the alert transaction's settled amount + settled currency (a
     * permitted ledger READ — the same surface `counterpartyIdForTransaction`
     * uses). The fallback band for a duplicate-only / first-time-only
     * dismissal is built from this so a suppression rule is still written
     * when the alert carries no per-merchant `latest_amount_minor` (CR-01).
     * Returns null when the transaction no longer exists.
     *
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

    /**
     * Resolve the merchant id for the alert's transaction (a permitted
     * ledger READ — the anomaly table keys per-transaction). Returns null
     * for an unresolved merchant, producing a normalized-name-fallback
     * rule.
     */
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
