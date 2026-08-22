<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

use Modules\Reports\Internal\Enums\ReportCurrencyMode;
use Modules\Reports\Internal\Enums\ReportDimension;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Enums\ReportMetricSelection;
use Modules\Reports\Internal\Enums\ReportPeriodPreset;
use Modules\Reports\Internal\Enums\ReportViz;

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
