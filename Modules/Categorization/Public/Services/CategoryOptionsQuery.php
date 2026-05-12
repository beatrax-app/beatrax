<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Public\Dto\CategoryOption;
use Modules\Core\Models\User;
use stdClass;

/**
 * Loads the flattened category list (Parent / Leaf) for pickers and inboxes.
 *
 * Visibility rule: a row is offered when its `user_id` is null (the seeded
 * default tree) OR matches the supplied user. This prevents custom
 * categories belonging to one user from leaking into another user's picker
 * once multi-user support lands.
 *
 * The DTO `CategoryOption` is ordered by `c.display_order` so the keyboard
 * picker's `1`-`9` shortcut always maps to the same nine items across
 * sessions.
 */
final class CategoryOptionsQuery
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return list<CategoryOption>
     */
    public function for(User $user): array
    {
        $userId = $user->id;

        $rows = $this->db->connection()
            ->table('categories as c')
            ->leftJoin('categories as p', 'c.parent_id', '=', 'p.id')
            ->where(static function ($q) use ($userId): void {
                $q->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->orderBy('c.display_order')
            ->select([
                'c.id',
                'c.name',
                'c.display_order',
                'p.name as parent_name',
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
        $name = self::toString($row->name);
        $parent = $row->parent_name === null ? null : self::toString($row->parent_name);
        $path = $parent === null ? $name : $parent.' / '.$name;

        return new CategoryOption(
            id: self::toInt($row->id),
            path: $path,
            displayOrder: self::toInt($row->display_order),
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
