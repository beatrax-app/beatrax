<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Models\UserPreference;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;

// Cards-default index with type-filter chips and a Cards/List toggle
// whose state persists in user_preferences.counterparty_index_view.
// The #[Url] attribute on $type keeps the active filter shareable via
// ?type={slug} links.
/**
 * @link ../../../../../.docs/features/counterparties/architecture.md
 */
final class CounterpartyIndex extends Component
{
    #[Url(as: 'type', except: 'all')]
    public string $type = 'all';

    public string $view = 'cards';

    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        // Materialise the user's persisted view-mode preference. The
        // row may not exist yet (the foundation table is created lazily
        // on first preference write), in which case the locked default
        // `cards` applies.
        $existing = $db->connection()->table('user_preferences')
            ->where('user_id', $currentUser->id())
            ->value('counterparty_index_view');

        if (is_string($existing) && $existing !== '') {
            $this->view = $existing;
        }
    }

    public function setType(string $type): void
    {
        $allowed = ['all', 'merchant', 'personal', 'bank', 'government', 'self', 'unknown'];
        if (in_array($type, $allowed, true)) {
            $this->type = $type;
        }
    }

    // updateOrCreate materialises the row on first write and updates it
    // idempotently thereafter — the unique index on user_preferences.user_id
    // enforces one row per user at the DB boundary.
    public function setView(string $view, CurrentUser $currentUser): void
    {
        if (! in_array($view, ['cards', 'list'], true)) {
            return;
        }

        $this->view = $view;

        UserPreference::query()->updateOrCreate(
            ['user_id' => $currentUser->id()],
            ['counterparty_index_view' => $view],
        );
    }

    public function render(
        CurrentUser $currentUser,
        CounterpartyIndexQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        return $views->make('counterparties::livewire.counterparty-index', [
            'rows' => $query->forUser($user, $this->type),
            'counts' => $query->countsByType($user),
            'activeType' => $this->type,
            'activeView' => $this->view,
        ]);
    }
}
