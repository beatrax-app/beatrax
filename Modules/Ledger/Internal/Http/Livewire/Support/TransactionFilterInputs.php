<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire\Support;

use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\SafeDate;

// What the filter panel sends, cleaned before it reaches a query. Held apart
// from the component because none of it needs the component: every answer is
// a function of the wire value alone.
final class TransactionFilterInputs
{
    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function withoutId(array $ids, int|string $removed): array
    {
        $target = DerivedRowId::fromWire($removed);

        return array_values(array_filter($ids, static fn (int $id): bool => $id !== $target));
    }

    // A non-numeric member is dropped rather than cast, since (int) 'abc' is
    // the same 0 an unselected option sends, which would narrow to nothing.
    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    public static function positiveIds(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if (! is_numeric($id)) {
                continue;
            }

            $numeric = (int) $id;
            if ($numeric > 0) {
                $clean[] = $numeric;
            }
        }

        return $clean;
    }

    // read lexically: 187 rows all dated 2026 came back as none, under a chip
    // printing "Before 2026" and a count claiming a filter was applied. The
    // picker and every preset emit a day, so anything else is a bad link.
    public static function supportedDay(string $raw): string
    {
        return SafeDate::dayOrNull($raw) === null ? '' : trim($raw);
    }
}
