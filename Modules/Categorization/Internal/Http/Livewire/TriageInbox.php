<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Dto\CategoryOption;
use Modules\Categorization\Public\Services\UncategorizedTriageQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use stdClass;

/**
 * `/uncategorized` triage inbox. Renders a cursor-paginated batch of
 * uncategorized transactions and a keyboard-driven category picker. Users
 * can stage assignments (`selectForRow`) and commit them all at once via
 * the `Save categories` button (`save`); `clearPending` resets the staged
 * state (Escape key).
 *
 * Per the locked-in Livewire convention every collaborator arrives as a
 * parameter on `render()` / action methods — no `boot()` injection (the
 * strict-rules ban property-based constructor injection on Components).
 *
 * The keymap `1`–`9` is wired in the Blade view via Alpine.js; the
 * action layer here just owns the staging map.
 */
final class TriageInbox extends Component
{
    /** @var array<int, ?int> map of transactionId => pending categoryId */
    public array $pending = [];

    public function selectForRow(int $transactionId, ?int $categoryId): void
    {
        $this->pending[$transactionId] = $categoryId;
    }

    public function clearPending(): void
    {
        $this->pending = [];
    }

    public function save(CurrentUser $currentUser, AssignsCategory $assign): void
    {
        $user = $currentUser->user();
        foreach ($this->pending as $transactionId => $categoryId) {
            $assign($transactionId, $categoryId, $user);
        }
        $this->pending = [];
    }

    public function render(
        CurrentUser $currentUser,
        UncategorizedTriageQuery $triage,
        DatabaseManager $db,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $batch = $triage->for($user);

        $categories = $this->loadCategoryOptions($db);
        $topNine = array_slice($categories, 0, 9);

        return $views->make('categorization::livewire.triage-inbox', [
            'batch' => $batch,
            'categories' => $categories,
            'topNine' => $topNine,
        ]);
    }

    /**
     * Loads the flattened category list (Parent / Leaf) ordered by display_order.
     *
     * @return array<int, CategoryOption>
     */
    private function loadCategoryOptions(DatabaseManager $db): array
    {
        $rows = $db->connection()
            ->table('categories as c')
            ->leftJoin('categories as p', 'c.parent_id', '=', 'p.id')
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
