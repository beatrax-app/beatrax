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
    public function label(): string
    {
        return Lang::get('reports::builder.group_header.'.match ($this) {
            self::Category => 'category',
            self::Counterparty => 'counterparty',
            self::Account => 'account',
            self::Period => 'month',
            self::Group => 'default',
        });
    }
}
