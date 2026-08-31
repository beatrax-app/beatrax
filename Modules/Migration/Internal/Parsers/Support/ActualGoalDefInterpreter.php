<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Internal\Dto\MigrationGoalDto;

final class ActualGoalDefInterpreter
{
    public function interpret(string $categorySourceExternalId, string $categoryName, string $goalDefJson, string $currency): ?MigrationGoalDto
    {
        $decoded = BoundedJson::decode($goalDefJson);
        if (! is_array($decoded)) {
            return null;
        }

        $amount = $this->flatGoalAmount($decoded);
        if ($amount === null) {
            return null;
        }

        return new MigrationGoalDto(
            categorySourceExternalId: $categorySourceExternalId,
            name: $categoryName,
            targetAmount: Money::ofMinor($amount, $currency),
            targetDate: $this->parseTargetDate($decoded),
        );
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     */
    private function flatGoalAmount(array $decoded): ?int
    {
        if (isset($decoded['steps']) || isset($decoded['schedule'])) {
            return null;
        }

        $type = $decoded['type'] ?? null;
        if (! in_array($type, ['simple', 'target', null], true)) {
            return null;
        }

        $amount = $decoded['amount'] ?? null;

        return is_int($amount) && $amount > 0 ? $amount : null;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     */
    private function parseTargetDate(array $decoded): ?CarbonImmutable
    {
        $raw = $decoded['targetDate'] ?? null;

        return is_string($raw) ? SafeDate::parseOrNull($raw) : null;
    }
}
