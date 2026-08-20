<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Support;

final class SeriesIds
{
    // Ids arrive from request payloads and other modules as whatever the
    // caller had: ints, numeric strings, the occasional null. Anything that
    // is not a positive integer is dropped rather than coerced to 0, which
    // would otherwise query for a row id that cannot exist.
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
