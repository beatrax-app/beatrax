<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Services;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Modules\Categorization\Public\Dto\CategoryOption;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\Support\CategoryPathName;

// A row is offered when its user_id is null (the seeded default tree) or
// matches the user. Both halves of the self-join apply that, so a leaf whose
// parent belongs to another user never leaks the foreign parent name.
final class CategoryOptionsQuery
{
    use CoercesScalars;

    /** @var array<string, list<CategoryOption>> */
    private array $cache = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Translator $translator,
    ) {}

    // The ledger screen mounts one picker per row, so an uncached read here is
    // a full categories self-join per transaction on screen. Keyed by reader
    // AND locale because the names resolve through the reader's language.
    /**
     * @return list<CategoryOption>
     */
    public function for(User $user): array
    {
        $userId = $user->id;
        $cacheKey = $userId.':'.$this->translator->getLocale();

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

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

        $paths = [];
        foreach ($rows as $row) {
            $paths[self::toInt($row->id)] = CategoryPathName::join(
                CategoryDisplayName::fromRow($row, 'parent'),
                CategoryDisplayName::fromRow($row, 'category') ?? '',
            );
        }

        // Distinct labels, but the display_order the query asked for: the
        // ordinal is assigned lowest id first and would otherwise reorder the
        // whole picker behind it.
        $labels = CategoryPathName::distinct($paths);

        $options = [];
        foreach ($rows as $row) {
            $id = self::toInt($row->id);
            $options[] = new CategoryOption(id: $id, path: $labels[$id], displayOrder: self::toInt($row->display_order));
        }

        $this->cache[$cacheKey] = $options;

        return $options;
    }
}
