<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Enums;

use Modules\Core\Public\Support\Lang;

// What the first column of a report holds, decided from the whole definition
// rather than the dimension alone: net_worth hides the dimension picker but the
// URL-bound property keeps its default, which headed a column of months with
// "Category". The CSV exporter guarded that and the screen did not.
enum ReportGroupHeading: string
{
    case Category = 'Category';

    case Counterparty = 'Counterparty';

    case Account = 'Account';

    case Period = 'Period';

    case Group = 'Group';

    public static function for(string $metric, string $dimension): self
    {
        if ($metric === ReportMetricSelection::NetWorth->value) {
            return self::Period;
        }

        return match (ReportDimension::tryFrom($dimension)) {
            ReportDimension::Category => self::Category,
            ReportDimension::Counterparty => self::Counterparty,
            ReportDimension::Account => self::Account,
            ReportDimension::TimeBucket => self::Period,
            null => self::Group,
        };
    }

    // The screen's own wording, which is the reader's language; the enum value
    // is the CSV column name, which is not translated in any exporter here.
    // The granularity is a required argument and nullable rather than defaulted:
    // a caller that has one may not forget to hand it over.
    public function label(?ReportGranularity $granularity): string
    {
        return Lang::get('reports::builder.group_header.'.match ($this) {
            self::Category => 'category',
            self::Counterparty => 'counterparty',
            self::Account => 'account',
            self::Period => self::bucketKey($granularity),
            self::Group => 'default',
        });
    }

    // A column of weeks headed "Month" is the heading disagreeing with the rows
    // under it. Period is the only case a granularity can speak to: the others
    // name a dimension, and no bucket size changes what a category is.
    private static function bucketKey(?ReportGranularity $granularity): string
    {
        return ($granularity ?? ReportGranularity::default()) === ReportGranularity::Weekly
            ? 'week'
            : 'month';
    }
}
