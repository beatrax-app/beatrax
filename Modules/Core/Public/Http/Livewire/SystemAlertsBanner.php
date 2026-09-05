<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\UserPreference;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\UpdateAlertKind;
use Modules\Core\Public\Events\UpdateInstallRequested;
use Modules\Core\Public\Services\SystemAlertQuery;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Services\UserPreferenceWriter;
use Modules\Core\Public\Support\DerivedRowId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SystemAlertsBanner extends Component
{
    public function render(
        CurrentUser $currentUser,
        SystemAlertQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $alerts = $query->active($user);
        $skippedVersions = $this->skippedVersionsFor($user->id);

        return $views->make('core::livewire.system-alerts-banner', [
            'alerts' => self::collapseIndistinguishable(
                $this->filterSkippedUpdates(self::installableHere($alerts), $skippedVersions),
            ),
        ]);
    }

    public function acknowledge(int|string $alertId, AcknowledgeSystemAlert $action, CurrentUser $currentUser): void
    {
        $alertId = DerivedRowId::fromWire($alertId);

        try {
            $action($alertId, $currentUser->user());
        } catch (NotFoundHttpException) {
            // Mounted on every page, so the same alert is dismissible from two
            // tabs at once and the second click names a row the first retired.
            // It is already in the state the click asked for, and the banner
            // re-renders without it rather than answering 404.
        }
    }

    // The user consenting to a verified update. This shared banner only raises
    // the request; the desktop module is the sole listener and turns it into a
    // re-verified download, so on web or mobile — where no listener is bound —
    // the click is inert and nothing outside the app stores ever installs.
    public function install(
        int|string $alertId,
        AcknowledgeSystemAlert $acknowledge,
        CurrentUser $currentUser,
        SystemAlertQuery $query,
    ): void {
        $alertId = DerivedRowId::fromWire($alertId);

        $alert = $query->visibleTo($alertId, $currentUser->user());
        if ($alert === null) {
            return;
        }

        $metadata = is_array($alert->metadata) ? $alert->metadata : [];
        $latestVersion = $metadata['latestVersion'] ?? null;
        if (is_string($latestVersion) && $latestVersion !== '') {
            UpdateInstallRequested::dispatch($latestVersion);
        }

        $this->acknowledge($alertId, $acknowledge, $currentUser);
    }

    // Persists metadata.latestVersion into skipped_update_versions AND
    // acknowledges the alert in one wire round-trip. Idempotent — re-skipping
    // an already-present version does not duplicate the entry.
    public function skipVersion(
        int|string $alertId,
        UserPreferenceWriter $preferences,
        CurrentUser $currentUser,
        AcknowledgeSystemAlert $acknowledge,
        SystemAlertQuery $query,
    ): void {
        $alertId = DerivedRowId::fromWire($alertId);

        $user = $currentUser->user();
        $alert = $query->visibleTo($alertId, $user);
        if ($alert === null) {
            return;
        }

        $metadata = is_array($alert->metadata) ? $alert->metadata : [];
        $latestVersion = $metadata['latestVersion'] ?? null;
        if (! is_string($latestVersion) || $latestVersion === '') {
            $this->acknowledge($alertId, $acknowledge, $currentUser);

            return;
        }

        $current = $this->skippedVersionsFor($user->id);

        // Only write when the version is genuinely new to the list: a repeat
        // skip used to materialise a preference row for nothing, and now it
        // would put a redundant op on the wire as well.
        if (! in_array($latestVersion, $current, true)) {
            $current[] = $latestVersion;
            $preferences->write($user->id, ['skipped_update_versions' => $current]);
        }

        $this->acknowledge($alertId, $acknowledge, $currentUser);
    }

    /**
     * @return list<string>
     */
    private function skippedVersionsFor(int $userId): array
    {
        $pref = UserPreference::withoutGlobalScopes()->where('user_id', $userId)->first();
        if ($pref === null) {
            return [];
        }

        $list = [];
        foreach ($pref->skipped_update_versions ?? [] as $value) {
            if (is_string($value)) {
                $list[] = $value;
            }
        }

        return $list;
    }

    // An update alert is the desktop updater's, and it is the only alert whose
    // buttons promise something outside this app. A phone loads no Desktop
    // module and updates through the App Store, so an iPhone was offering
    // "Install on next launch" over a click install() already knows is inert.
    /**
     * @param  Collection<int, SystemAlert>  $alerts
     * @return Collection<int, SystemAlert>
     */
    private static function installableHere(Collection $alerts): Collection
    {
        if (! UserDataPathService::isMobileRuntime()) {
            return $alerts;
        }

        /** @var Collection<int, SystemAlert> $filtered */
        $filtered = $alerts->reject(
            static fn (SystemAlert $alert): bool => UpdateAlertKind::tryFrom($alert->kind) !== null,
        )->values();

        return $filtered;
    }

    // Rows written before the dedup key existed, and rows a peer's own probe
    // raised, can still say the same thing twice. Keyed on everything the body
    // paints — line, timestamp to the minute, and the kind that picks the
    // buttons — so only a row nobody could tell from another is dropped.
    /**
     * @param  Collection<int, SystemAlert>  $alerts
     * @return Collection<int, SystemAlert>
     */
    private static function collapseIndistinguishable(Collection $alerts): Collection
    {
        /** @var array<string, true> $seen */
        $seen = [];

        /** @var Collection<int, SystemAlert> $kept */
        $kept = $alerts->filter(static function (SystemAlert $alert) use (&$seen): bool {
            $fingerprint = self::asPainted($alert);

            if (isset($seen[$fingerprint])) {
                return false;
            }

            $seen[$fingerprint] = true;

            return true;
        })->values();

        return $kept;
    }

    // The stored sentence AND the copy line beside it: the banner re-renders
    // from metadata in the reader's language, so two rows carrying one English
    // message can still reach the screen as two different sentences.
    private static function asPainted(SystemAlert $alert): string
    {
        $metadata = is_array($alert->metadata) ? $alert->metadata : [];
        $copy = json_encode($metadata['copy'] ?? null);

        return implode('|', [
            $alert->kind,
            $alert->severity,
            $alert->message,
            is_string($copy) ? $copy : '',
            $alert->created_at->format('Y-m-d H:i'),
        ]);
    }

    /**
     * @param  Collection<int, SystemAlert>  $alerts
     * @param  list<string>  $skippedVersions
     * @return Collection<int, SystemAlert>
     */
    private function filterSkippedUpdates(Collection $alerts, array $skippedVersions): Collection
    {
        if ($skippedVersions === []) {
            return $alerts;
        }

        /** @var Collection<int, SystemAlert> $filtered */
        $filtered = $alerts->reject(static function (SystemAlert $alert) use ($skippedVersions): bool {
            if ($alert->kind !== UpdateAlertKind::Available->value) {
                return false;
            }
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $latestVersion = $metadata['latestVersion'] ?? null;

            return is_string($latestVersion) && in_array($latestVersion, $skippedVersions, true);
        })->values();

        return $filtered;
    }
}
