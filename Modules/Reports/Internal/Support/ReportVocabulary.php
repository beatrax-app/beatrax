<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

use Modules\Reports\Internal\Enums\ReportCurrencyMode;
use Modules\Reports\Internal\Enums\ReportDimension;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Enums\ReportMetricSelection;
use Modules\Reports\Internal\Enums\ReportPeriodPreset;
use Modules\Reports\Internal\Enums\ReportViz;
use Modules\Reports\Internal\Exceptions\InvalidReportPeriod;

/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md
 */
// One home for "a reader-supplied word this build does not know becomes the
// default". Two boundaries build a definition — the builder's #[Url] rail and
// the export query string — and the defect being fixed was one of them being
// hardened while the other was not.
final class ReportVocabulary
{
    public static function metric(?string $value): string
    {
        return (ReportMetricSelection::tryFrom((string) $value) ?? ReportMetricSelection::default())->value;
    }

    public static function dimension(?string $value): string
    {
        return (ReportDimension::tryFrom((string) $value) ?? ReportDimension::default())->value;
    }

    public static function periodPreset(?string $value): string
    {
        return (ReportPeriodPreset::tryFrom((string) $value) ?? ReportPeriodPreset::default())->value;
    }

    // The address bar's own copy of the question above, for the surfaces that
    // read one. Coercing there drew a full report over a period the reader had
    // not asked for, with every button in the group unpressed and nothing said;
    // the `from`/`to` rail beside it refuses, and this is the same refusal.
    /**
     * @throws InvalidReportPeriod
     */
    public static function periodPresetNamedHere(?string $value): string
    {
        $named = ReportPeriodPreset::tryFrom((string) $value);

        if ($named === null && $value !== null && $value !== '') {
            throw InvalidReportPeriod::unknownPreset($value);
        }

        return ($named ?? ReportPeriodPreset::default())->value;
    }

    public static function currencyMode(?string $value): string
    {
        return (ReportCurrencyMode::tryFrom((string) $value) ?? ReportCurrencyMode::default())->value;
    }

    public static function viz(?string $value): string
    {
        return (ReportViz::tryFrom((string) $value) ?? ReportViz::Table)->value;
    }

    public static function granularity(?string $value): ReportGranularity
    {
        return ReportGranularity::tryFrom((string) $value) ?? ReportGranularity::default();
    }

    // The stored column is a different boundary from the rails above. A word
    // from the address bar becomes the default because the alternative is a
    // 500; a word found in the database was written by a peer on a newer build,
    // and answering for it here is deciding what that peer meant.
    public static function storedGranularity(?string $value): ?ReportGranularity
    {
        return ReportGranularity::tryFrom((string) $value);
    }

    // Livewire rehydrates an array-bound #[Url] property from the client
    // payload with whatever it holds — strings, nulls, nested arrays, a keyed
    // map — so an int-typed filter over it is a TypeError waiting for a crafted
    // ?account[]=. Anything that is not a positive whole number is dropped.
    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<int>
     */
    public static function ids(array $raw): array
    {
        $ids = [];

        foreach ($raw as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
