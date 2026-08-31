<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Enums\AnomalyDetector;
use Modules\Anomaly\Public\Dto\AnomalySuppressionRuleDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

final readonly class AnomalySuppressionRuleQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private CounterpartyProfileQuery $counterpartyQuery,
        private BaseCurrency $baseCurrency,
    ) {}

    /**
     * @return list<AnomalySuppressionRuleDto>
     */
    public function forUser(User $user): array
    {
        $rows = $this->db->connection()->table('anomaly_suppression_rules')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $counterpartyIds = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $id = self::toPositiveIntOrNull($row->counterparty_id ?? null);
            if ($id !== null) {
                $counterpartyIds[] = $id;
            }
        }

        $names = [];
        if ($counterpartyIds !== []) {
            $identities = $this->counterpartyQuery->identitiesForIds(
                $user,
                array_values(array_unique($counterpartyIds)),
            );
            foreach ($identities as $id => $identity) {
                $names[$id] = $identity['displayName'];
            }
        }

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $detector = AnomalyDetector::tryFrom(self::toString($row->detector ?? null));
            if ($detector === null) {
                // Unreachable behind the detector CHECK trigger; a rule naming
                // a detector no build has can mute nothing, and rendering it
                // put a raw lang key on the settings screen.
                continue;
            }

            $counterpartyId = self::toPositiveIntOrNull($row->counterparty_id ?? null);
            $currency = $this->toCurrency($row->currency ?? null, $user);

            $result[] = new AnomalySuppressionRuleDto(
                id: self::toInt($row->id ?? null),
                counterpartyId: $counterpartyId,
                displayName: $counterpartyId !== null ? ($names[$counterpartyId] ?? '') : '',
                detector: $detector,
                direction: self::toString($row->direction ?? null),
                bandLow: Money::ofMinor(self::toInt($row->amount_band_low_minor ?? null), $currency),
                bandHigh: Money::ofMinor(self::toInt($row->amount_band_high_minor ?? null), $currency),
                currency: $currency,
            );
        }

        return $result;
    }

    private function toCurrency(mixed $value, User $user): string
    {
        return is_string($value) && $value !== '' ? $value : $this->baseCurrency->forUser($user);
    }
}
