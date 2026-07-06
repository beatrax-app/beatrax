<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Carbon\CarbonImmutable;
use JsonException;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Migration\Public\Dto\MigrationGoalDto;
use Throwable;

/**
 * Interprets an Actual `categories.goal_def` JSON blob (Open Question 3,
 * RESEARCH.md Assumption A7) — Actual's own goal-template mini-language,
 * NOT the same shape as YNAB's `goal_type`/`goal_target`/`goal_target_month`
 * columns (which don't exist in the CSV export anyway — Summary finding 1).
 *
 * Scoped to the SUPPORTED FLAT SUBSET only: a single target amount with an
 * optional target date (`{"type": "simple"|"target", "amount": <int minor>,
 * "targetDate": <?string ISO date>}`). Any other shape — a multi-step
 * template (`"steps"`), a percentage-of-income schedule, or any other
 * grammar this class does not recognise — returns null so the caller
 * (`ActualParser`) emits an `UnmappedItemDto('extra')` instead of coercing
 * it into a lossy flat approximation (Req 8's "else reported as unmapped,
 * not dropped").
 *
 * `json_decode()` of the source-supplied `goal_def` blob is bounded by byte
 * size and nesting depth (T-13.5-10, Denial of Service) before any decoding
 * is attempted.
 */
final class ActualGoalDefInterpreter
{
    private const MAX_JSON_BYTES = 65536;

    private const MAX_JSON_DEPTH = 20;

    /**
     * Returns a `MigrationGoalDto` for a flat `goal_def`, or null when the
     * grammar is anything else (non-flat/unsupported).
     */
    public function interpret(string $categorySourceExternalId, string $categoryName, string $goalDefJson, string $currency): ?MigrationGoalDto
    {
        $decoded = $this->boundedDecode($goalDefJson);
        if (! is_array($decoded)) {
            return null;
        }

        // A multi-step template/schedule is explicitly non-flat — never
        // reduced to a single target (Open Question 3's scope decision).
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
