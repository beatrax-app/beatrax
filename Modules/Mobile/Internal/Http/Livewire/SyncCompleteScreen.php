<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Sync\InitialSyncPuller;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\RelayEndpointHost;

final class SyncCompleteScreen extends Component
{
    public int $recordsApplied = 0;

    // The peer this device just caught up from, named rather than described,
    // so the confirmation is about a device the user recognises.
    public string $peerName = '';

    // Whether a relay endpoint is configured. It decides which of the two
    // away-from-home lines is true for THIS device, and claiming a capability
    // that is not set up would be worse than saying nothing.
    public bool $hasRelay = false;

    // The label the Data & devices button carries, read from the copy that
    // button renders. This screen once told the reader there was no sync
    // button while the next screen was built around one.
    #[Locked]
    public string $syncAction = '';

    public function mount(
        CurrentUser $currentUser,
        InitialSyncPuller $puller,
        DeviceRegistryService $devices,
        RelayEndpointHost $relayHost,
    ): void {
        $userId = $currentUser->id();

        $this->recordsApplied = $puller->progress($userId)['records_applied'];

        $names = $devices->otherDeviceNames($userId);
        $first = reset($names);

        $this->peerName = is_string($first) && $first !== ''
            ? $first
            : Lang::get('mobile::sync_complete.peer_fallback');

        $this->hasRelay = $relayHost->host() !== null;
        $this->syncAction = Lang::get('mobile::sync.sync_now');
    }

    public function continueToApp(UrlGenerator $urls): void
    {
        $this->redirect(Destination::Dashboard->urlFrom($urls), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.sync-complete-screen');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.lock', ['title' => Lang::get('mobile::sync_complete.page_title').' · Beatrax']);

        return $view;
    }
}
