<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Support;

final class SeriesIds
{
    // Non-positive input is dropped, not coerced to 0, which would otherwise
    // put a row id that cannot exist into the query.
    /**
     * @param  array<int|string, mixed>  $seriesIds
     * @return list<int>
     */
    public static function normalise(array $seriesIds): array
    {
        $clean = [];

        foreach ($seriesIds as $id) {
            $i = is_numeric($id) ? (int) $id : 0;
            if ($i > 0) {
                $clean[] = $i;
            }
        }

        return array_values(array_unique($clean));
    }
}
