<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Recurring\Public\Actions\SetDriftThresholdForSeries;

/**
 * Per-series drift threshold override popover. Mounts inline on /drift
 * grouped-by-series headers and on /recurring/series/{id}; the same
 * component drives both surfaces so the popover chrome stays
 * consistent.
 *
 * The save path delegates to the Recurring-side
 * `SetDriftThresholdForSeries` Public Action — DriftAlerts itself never
 * writes to recurring_series, keeping the
 * `noRecurringSeriesWritesFromDriftAlerts` invariant green without an
 * exemption list.
 *
 * Constructor-injection is banned on Livewire `Component` subclasses by
 * phpstan-strict-rules; service collaborators arrive as method
 * parameters on `mount()`, `save()`, and `render()`.
 *
 * `currentValue === null` means "use the user-global default" — the
 * popover's "Use global default" option saves null back. The popover
 * displays "5 (global)" or the user-global value when no series-level
 * override is active; the popover trigger label adapts accordingly.
 */
final class DriftThresholdEditor extends Component
{
    /** @var list<int> */
    public const OPTIONS = [1, 2, 5, 10, 25, 50];

    public int $recurringSeriesId = 0;

    public ?int $currentValue = null;

    public function mount(int $recurringSeriesId, CurrentUser $currentUser, DatabaseManager $db): void
    {
        $this->recurringSeriesId = $recurringSeriesId;

        $row = $db->connection()->table('recurring_series')
            ->where('id', $recurringSeriesId)
            ->where('user_id', $currentUser->user()->id)
            ->first(['drift_threshold_percent']);

        if ($row === null) {
            $this->currentValue = null;

            return;
        }

        $raw = $row->drift_threshold_percent ?? null;
        $this->currentValue = is_numeric($raw) ? (int) $raw : null;
    }

    public function save(int|string $newValue, CurrentUser $currentUser, SetDriftThresholdForSeries $action): void
    {
        // Validate the inbound value before reaching the Public Action.
        // The action carries its own whitelist (raises
        // InvalidArgumentException on a non-allowed value) but a
        // tampered Livewire payload that PHP-coerces to 0 (e.g. the
        // string "abc") would surface that exception as a noisy
        // user-facing error. Silently reject anything outside the
        // popover's allowed set instead.
        if ($newValue === 'global') {
            $effective = null;
        } elseif (is_int($newValue) || (is_string($newValue) && ctype_digit($newValue))) {
            $candidate = (int) $newValue;
            if (! in_array($candidate, self::OPTIONS, true)) {
                return;
            }
            $effective = $candidate;
        } else {
            return;
        }

        ($action)($this->recurringSeriesId, $currentUser->user(), $effective);

        $this->currentValue = $effective;
        $this->dispatch('toast', message: 'Threshold updated.');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('drift-alerts::livewire.drift-threshold-editor', [
            'currentValue' => $this->currentValue,
            'options' => self::OPTIONS,
        ]);
    }
}
