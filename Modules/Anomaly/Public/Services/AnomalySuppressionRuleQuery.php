<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Public\Dto\AnomalySuppressionRuleDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final readonly class AnomalySuppressionRuleQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private CounterpartyProfileQuery $counterpartyQuery,
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
            $id = self::toIntOrNull($row->counterparty_id ?? null);
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
            $counterpartyId = self::toIntOrNull($row->counterparty_id ?? null);
            $currency = self::toCurrency($row->currency ?? null);

            $result[] = new AnomalySuppressionRuleDto(
                id: self::toInt($row->id ?? null),
                counterpartyId: $counterpartyId,
                displayName: $counterpartyId !== null ? ($names[$counterpartyId] ?? '') : '',
                detector: self::toString($row->detector ?? null),
                direction: self::toString($row->direction ?? null),
                bandLow: Money::ofMinor(self::toInt($row->amount_band_low_minor ?? null), $currency),
                bandHigh: Money::ofMinor(self::toInt($row->amount_band_high_minor ?? null), $currency),
                currency: $currency,
            );
        }

        return $result;
    }

    private static function toCurrency(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'EUR';
    }

    private static function toIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
