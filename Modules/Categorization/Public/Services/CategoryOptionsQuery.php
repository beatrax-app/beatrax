<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Modules\Categorization\Public\Dto\CategoryOption;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use stdClass;

// A row is offered when its user_id is null (the seeded default tree) or
// matches the user. Both halves of the self-join apply that, so a leaf whose
// parent belongs to another user never leaks the foreign parent name.
final class CategoryOptionsQuery
{
    use CoercesScalars;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return list<CategoryOption>
     */
    public function for(User $user): array
    {
        $userId = $user->id;

        $rows = $this->db->connection()
            ->table('categories as c')
            ->leftJoin('categories as p', static function (JoinClause $join) use ($userId): void {
                $join->on('c.parent_id', '=', 'p.id')
                    ->where(static function (QueryBuilder $q) use ($userId): void {
                        $q->whereNull('p.user_id')->orWhere('p.user_id', $userId);
                    });
            })
            ->where(static function (QueryBuilder $q) use ($userId): void {
                $q->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->orderBy('c.display_order')
            ->select([
                'c.id',
                'c.display_order',
                ...CategoryDisplayName::columns('c'),
                ...CategoryDisplayName::columns('p', 'parent'),
            ])
            ->get();

        $options = [];
        foreach ($rows as $row) {
            $options[] = $this->mapOption($row);
        }

        return $options;
    }

    private function mapOption(stdClass $row): CategoryOption
    {
        $name = CategoryDisplayName::fromRow($row, 'category') ?? '';
        $parent = CategoryDisplayName::fromRow($row, 'parent');
        $path = $parent === null ? $name : $parent.' / '.$name;

        return new CategoryOption(
            id: self::toInt($row->id),
            path: $path,
            displayOrder: self::toInt($row->display_order),
        );
    }
}
