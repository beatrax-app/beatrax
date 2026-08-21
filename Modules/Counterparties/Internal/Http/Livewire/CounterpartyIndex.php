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
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;

final class CounterpartyIndex extends Component
{
    #[Url(as: 'type', except: 'all')]
    public string $type = 'all';

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
        $allowed = ['all', 'merchant', 'personal', 'bank', 'government', 'self', 'unknown'];
        if (in_array($type, $allowed, true)) {
            $this->type = $type;
        }
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

        return $views->make('counterparties::livewire.counterparty-index', [
            'rows' => $query->forUser($user, $this->type),
            'counts' => $query->countsByType($user),
            'activeType' => $this->type,
            'activeView' => $this->view,
        ]);
    }
}
