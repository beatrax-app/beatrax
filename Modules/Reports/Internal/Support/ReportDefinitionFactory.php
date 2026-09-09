<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

use Modules\Ledger\Public\Enums\AmountDirection;
use Modules\Reports\Internal\Aggregation\PeriodPresetResolver;
use Modules\Reports\Internal\Dto\ReportDefinition;

// A stored definition is a synced LWW column, so a peer on a different build is
// a realistic source of a word this one does not know. Nothing throws: one
// unreadable row used to 500 /reports, and the dashboard with it. Granularity is
// the one field dropped rather than coerced, as customFrom already is below.
final class ReportDefinitionFactory
{
    public static function fromStored(mixed $raw): ReportDefinition
    {
        $decoded = is_array($raw) ? self::stringKeyed($raw) : DefinitionJsonDecoder::decode($raw);

        return new ReportDefinition(
            metric: ReportVocabulary::metric(self::string($decoded, 'metric')),
            dimension: ReportVocabulary::dimension(self::string($decoded, 'dimension')),
            periodPreset: ReportVocabulary::periodPreset(self::string($decoded, 'periodPreset')),
            granularity: ReportVocabulary::storedGranularity(self::string($decoded, 'granularity')),
            currencyMode: ReportVocabulary::currencyMode(self::string($decoded, 'currencyMode')),
            viz: ReportVocabulary::viz(self::string($decoded, 'viz')),
            // Dropped rather than replayed when it is not a date: the builder
            // then asks for the range again, which is a question the reader can
            // answer, instead of resolving to a window nobody chose.
            customFrom: PeriodPresetResolver::tryParseDate(self::string($decoded, 'customFrom'))?->toDateString(),
            customTo: PeriodPresetResolver::tryParseDate(self::string($decoded, 'customTo'))?->toDateString(),
            compare: (bool) ($decoded['compare'] ?? false),
            accounts: ReportVocabulary::ids(self::list($decoded, 'accounts')),
            categories: ReportVocabulary::ids(self::list($decoded, 'categories')),
            counterparties: ReportVocabulary::ids(self::list($decoded, 'counterparties')),
            amountMin: self::string($decoded, 'amountMin'),
            amountMax: self::string($decoded, 'amountMax'),
            amountDirection: (AmountDirection::tryFrom((string) self::string($decoded, 'amountDirection')) ?? AmountDirection::Both)->value,
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $raw): array
    {
        $result = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private static function string(array $decoded, string $key): ?string
    {
        $value = $decoded[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<array-key, mixed>
     */
    private static function list(array $decoded, string $key): array
    {
        $value = $decoded[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
