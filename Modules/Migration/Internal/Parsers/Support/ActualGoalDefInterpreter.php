<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Carbon\CarbonImmutable;
use JsonException;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Public\Dto\MigrationGoalDto;
use Throwable;

/**
 * @link ../../../../../.docs/features/migration/architecture.md
 */
final class ActualGoalDefInterpreter
{
    private const MAX_JSON_BYTES = 65536;

    private const MAX_JSON_DEPTH = 20;

    public function interpret(string $categorySourceExternalId, string $categoryName, string $goalDefJson, string $currency): ?MigrationGoalDto
    {
        $decoded = $this->boundedDecode($goalDefJson);
        if (! is_array($decoded)) {
            return null;
        }

        // A multi-step template/schedule is explicitly non-flat — never
        // reduced to a single target.
        if (isset($decoded['steps']) || isset($decoded['schedule'])) {
            return null;
        }

        $type = $decoded['type'] ?? null;
        if (! in_array($type, ['simple', 'target', null], true)) {
            // An unrecognised/complex type keyword — treat conservatively
            // as non-flat rather than guessing at its shape.
            return null;
        }

        $amount = $decoded['amount'] ?? null;
        if (! is_int($amount) || $amount <= 0) {
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
    private function parseTargetDate(array $decoded): ?CarbonImmutable
    {
        $raw = $decoded['targetDate'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function boundedDecode(string $json): mixed
    {
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES) {
            return null;
        }

        try {
            return json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
