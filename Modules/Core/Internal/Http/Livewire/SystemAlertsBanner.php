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
 * Persistent dashboard banner that surfaces every un-acknowledged
 * `system_alerts` row visible to the current user — their own rows
 * plus system-wide (user_id IS NULL) rows. Sits at the top of every
 * authenticated page via the `@livewire('core.system-alerts-banner')`
 * slot in `resources/views/layouts/app.blade.php`.
 *
 * Severity-first stack:
 *   critical → warning → info
 * with chronological tie-break inside each tier (locked by
 * `SystemAlertQuery::active`).
 *
 * Suppression rule for the auto-update kinds: an `update.available`
 * row whose `metadata.latestVersion` value is already in the current
 * user's `user_preferences.skipped_update_versions` array is filtered
 * out at render time. The filter intentionally ignores `update.stale`
 * and `update.critical` rows — those bypass the skip list because
 * their threat models (long-unsupported version, security-fix
 * critical update) explicitly override the user's earlier dismissal.
 *
 * No constructor DI — phpstan-strict-rules bans it on Livewire
 * Component subclasses. Method-parameter DI on `render()` /
 * `acknowledge()` / `skipVersion()` instead. The class deliberately
 * holds zero properties so the component is stateless across
 * re-renders; Livewire re-runs `render()` after every action returns,
 * so the post-acknowledge view automatically drops the dismissed row.
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

    /**
     * Persists the alert's `metadata.latestVersion` into the current
     * user's `user_preferences.skipped_update_versions` JSON column
     * AND acknowledges the alert in one wire round-trip. Idempotent
     * on the skip list — re-skipping a version that is already
     * present does not duplicate the entry.
     *
     * Method-parameter DI per Livewire conventions; the wire action
     * resolves dependencies fresh on every invocation.
     */
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
     * Reads the current user's skip list from `user_preferences`,
     * returning an empty list when no row exists yet for the user.
     *
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
     * Drops every `update.available` row whose
     * `metadata.latestVersion` field is in the user's skip list.
     * Every other alert kind passes through untouched.
     *
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
