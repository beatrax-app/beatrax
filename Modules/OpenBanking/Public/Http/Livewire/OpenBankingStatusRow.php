<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\OpenBanking\Internal\Dto\OpenBankingConnectionView;
use Modules\OpenBanking\Internal\Enums\ConsentStatus;
use Modules\OpenBanking\Internal\Services\OpenBankingConnectionQuery;

final class OpenBankingStatusRow extends Component
{
    public string $statusText = '';

    public bool $expired = false;

    public function mount(CurrentUser $currentUser, OpenBankingConnectionQuery $query): void
    {
        $connected = array_values(array_filter(
            $query->forUser($currentUser->user()->id),
            static fn (OpenBankingConnectionView $view): bool => $view->enabled,
        ));

        if ($connected === []) {
            $this->statusText = Lang::get('openbanking::messages.status_row.not_connected');
            $this->expired = false;

            return;
        }

        // Both endings read as red and offer the same fix, but they are not the
        // same sentence: a consent the bank withdrew never expired, and naming
        // the wrong cause sends the reader to check a date that is still fine.
        $lapsed = array_values(array_filter(
            $connected,
            static fn (OpenBankingConnectionView $view): bool => $view->consentStatus->needsReconnect(),
        ));
        if ($lapsed !== []) {
            $this->statusText = Lang::get(self::lapsedKeyFor($lapsed));
            $this->expired = true;

            return;
        }

        $this->statusText = Lang::get('openbanking::messages.status_row.connected', [
            'bank' => implode(', ', array_map(
                static fn (OpenBankingConnectionView $view): string => $view->bankDisplayName,
                $connected,
            )),
            'when' => self::oldestSyncFor($connected),
        ]);
        $this->expired = false;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('openbanking::livewire.open-banking-status-row');
    }

    /**
     * @param  list<OpenBankingConnectionView>  $lapsed
     */
    private static function lapsedKeyFor(array $lapsed): string
    {
        $revoked = array_any(
            $lapsed,
            static fn (OpenBankingConnectionView $view): bool => $view->consentStatus === ConsentStatus::Revoked,
        );

        return $revoked
            ? 'openbanking::messages.status_row.revoked'
            : 'openbanking::messages.status_row.expired';
    }

    // The weakest link, not the freshest: this line answers "how current is my
    // data", and a bank that has not synced in a month is the answer even when
    // the one beside it synced an hour ago.
    /**
     * @param  list<OpenBankingConnectionView>  $connected
     */
    private static function oldestSyncFor(array $connected): string
    {
        $oldest = null;
        foreach ($connected as $view) {
            if ($view->lastSuccessfulSyncAt === null) {
                return Lang::get('openbanking::messages.status_row.never');
            }
            $oldest = $oldest instanceof CarbonImmutable && $oldest->lessThan($view->lastSuccessfulSyncAt)
                ? $oldest
                : $view->lastSuccessfulSyncAt;
        }

        return $oldest?->diffForHumans() ?? Lang::get('openbanking::messages.status_row.never');
    }
}
