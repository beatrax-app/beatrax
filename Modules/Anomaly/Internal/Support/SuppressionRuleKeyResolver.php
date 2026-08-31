<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Support;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Enums\AnomalyDetector;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Services\BaseCurrency;

// One derivation shared by the write and the undo. Two of them drifting is how
// a dismissal could mute a merchant that the matching undo could not find.
final readonly class SuppressionRuleKeyResolver
{
    use CoercesScalars;

    private const float BAND_LOW_MULTIPLIER = 0.85;

    private const float BAND_HIGH_MULTIPLIER = 1.15;

    public function __construct(
        private DatabaseManager $db,
        private BaseCurrency $baseCurrency,
    ) {}

    // Null when the alert names no detector this build knows, or when the band
    // cannot be resolved at all — neither can mute anything.
    public function forAlert(AnomalyAlert $alert, User $user): ?SuppressionRuleKey
    {
        $detectors = AnomalyDetector::listFrom($alert->reasons);
        if ($detectors === []) {
            return null;
        }

        $currency = is_string($alert->currency) && $alert->currency !== '' ? $alert->currency : null;

        $latestMinor = $alert->latest_amount_minor;
        if ($latestMinor === null) {
            // A duplicate-only / first-time-only alert carries no per-merchant
            // baseline, so the band falls back to the charge's own settled
            // amount — otherwise those detectors could never be suppressed.
            $settled = $this->settledChargeForTransaction($user, $alert->transaction_id);
            if ($settled === null) {
                return null;
            }
            $latestMinor = $settled['amount_minor'];
            $currency ??= $settled['currency'];
        }

        $boundA = (int) round(self::BAND_LOW_MULTIPLIER * $latestMinor);
        $boundB = (int) round(self::BAND_HIGH_MULTIPLIER * $latestMinor);

        return new SuppressionRuleKey(
            counterpartyId: $this->counterpartyIdForTransaction($user, $alert->transaction_id),
            direction: $alert->direction,
            // For an expense (negative) the 1.15x bound is the more-negative
            // one, so min/max — not the multipliers — decide which end is which.
            bandLowMinor: min($boundA, $boundB),
            bandHighMinor: max($boundA, $boundB),
            currency: $currency ?? $this->baseCurrency->forUser($user),
            detectors: $detectors,
        );
    }

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
            : $this->baseCurrency->forUser($user);

        return ['amount_minor' => (int) $amount, 'currency' => $currency];
    }

    // A permitted ledger read (crossModuleRawTableWrites pins only writes).
    // Null means an unresolved merchant and a counterparty_id IS NULL
    // fallback rule, which the evaluator still matches.
    private function counterpartyIdForTransaction(User $user, int $transactionId): ?int
    {
        $row = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('id', $transactionId)
            ->first(['counterparty_id']);

        if ($row === null) {
            return null;
        }

        return self::toPositiveIntOrNull($row->counterparty_id ?? null);
    }
}
