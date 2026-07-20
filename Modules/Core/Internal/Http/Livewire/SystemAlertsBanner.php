<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\UserPreference;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\SystemAlertQuery;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
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
            'alerts' => $this->filterSkippedUpdates($alerts, $skippedVersions),
        ]);
    }

    public function acknowledge(int $alertId, AcknowledgeSystemAlert $action, CurrentUser $currentUser): void
    {
        $action($alertId, $currentUser->user());
    }

    // Persists metadata.latestVersion into skipped_update_versions AND
    // acknowledges the alert in one wire round-trip. Idempotent — re-skipping
    // an already-present version does not duplicate the entry.
    public function skipVersion(
        int $alertId,
        DatabaseManager $db,
        CurrentUser $currentUser,
        AcknowledgeSystemAlert $acknowledge,
    ): void {
        $user = $currentUser->user();
        $alert = SystemAlert::withoutGlobalScopes()->find($alertId);
        if ($alert === null) {
            return;
        }

        $metadata = is_array($alert->metadata) ? $alert->metadata : [];
        $latestVersion = $metadata['latestVersion'] ?? null;
        if (! is_string($latestVersion) || $latestVersion === '') {
            $acknowledge($alertId, $user);

            return;
        }

        $db->connection()->transaction(static function () use ($user, $latestVersion): void {
            /** @var UserPreference $pref */
            $pref = UserPreference::query()->firstOrCreate(['user_id' => $user->id]);
            $current = $pref->skipped_update_versions ?? [];
            if (! in_array($latestVersion, $current, true)) {
                $current[] = $latestVersion;
                $pref->skipped_update_versions = array_values($current);
                $pref->save();
            }
        });

        $acknowledge($alertId, $user);
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
            if ($alert->kind !== 'update.available') {
                return false;
            }
            $metadata = is_array($alert->metadata) ? $alert->metadata : [];
            $latestVersion = $metadata['latestVersion'] ?? null;

            return is_string($latestVersion) && in_array($latestVersion, $skippedVersions, true);
        })->values();

        return $filtered;
    }
}
