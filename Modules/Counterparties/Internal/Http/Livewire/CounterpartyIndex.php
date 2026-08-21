<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserPreferenceWriter;
use Modules\Counterparties\Internal\Enums\CounterpartyTypeFilter;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;

final class CounterpartyIndex extends Component
{
    #[Url(as: 'type', except: CounterpartyTypeFilter::All->value)]
    public string $type = CounterpartyTypeFilter::All->value;

    public string $view = 'cards';

    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        // The user_preferences row is materialised lazily on the first preference
        // write, so its absence is what the `cards` default stands in for.
        $existing = $db->connection()->table('user_preferences')
            ->where('user_id', $currentUser->id())
            ->value('counterparty_index_view');

        if (is_string($existing) && $existing !== '') {
            $this->view = $existing;
        }
    }

    public function setType(string $type): void
    {
        $filter = CounterpartyTypeFilter::tryFrom($type);
        if ($filter !== null) {
            $this->type = $filter->value;
        }
    }

    // #[Url] assigns the raw query parameter, so the property can hold a
    // spelling no chip offers; an unreadable filter is no filter rather than a
    // `where type = <garbage>` that renders an empty grid with no chip lit.
    private function activeFilter(): CounterpartyTypeFilter
    {
        return CounterpartyTypeFilter::tryFrom($this->type) ?? CounterpartyTypeFilter::All;
    }

    // Routed through the shared writer: it materialises the row on first
    // write, and it is the single place the preference change is put on the
    // op log, so a toggle made after pairing reaches the other device.
    public function setView(string $view, CurrentUser $currentUser, UserPreferenceWriter $preferences): void
    {
        if (! in_array($view, ['cards', 'list'], true)) {
            return;
        }

        $this->view = $view;

        $preferences->write($currentUser->id(), ['counterparty_index_view' => $view]);
    }

    public function render(
        CurrentUser $currentUser,
        CounterpartyIndexQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $activeFilter = $this->activeFilter();

        return $views->make('counterparties::livewire.counterparty-index', [
            'rows' => $query->forUser($user, $activeFilter),
            'counts' => $query->countsByType($user),
            'activeFilter' => $activeFilter,
            'activeView' => $this->view,
        ]);
    }
}
