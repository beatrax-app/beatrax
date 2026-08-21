<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\DriftThresholdOptions;
use Modules\Core\Public\Support\Lang;
use Modules\Recurring\Public\Actions\SetDriftThresholdForSeries;

final class DriftThresholdEditor extends Component
{
    use DispatchesToast;

    /** @var list<int> */
    public const OPTIONS = DriftThresholdOptions::PERCENTS;

    public int $recurringSeriesId = 0;

    public ?int $currentValue = null;

    // A parent that renders one editor per alert group already holds the whole
    // column and says so with $currentValueLoaded; null on its own cannot, since
    // null is also the answer for a series that follows the global default. A
    // page that mounts this alone passes neither and reads its own row.
    public function mount(
        int $recurringSeriesId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        ?int $currentValue = null,
        bool $currentValueLoaded = false,
    ): void {
        $this->recurringSeriesId = $recurringSeriesId;

        if ($currentValueLoaded) {
            $this->currentValue = $currentValue;

            return;
        }

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
        // The Public Action carries its own whitelist (raises
        // InvalidArgumentException on a non-allowed value), but a tampered
        // payload that PHP-coerces to 0 would surface that as a noisy
        // user-facing error — silently reject it here instead.
        if ($newValue === 'global') {
            $effective = null;
        } elseif (is_int($newValue) || ctype_digit($newValue)) {
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
        $this->toast(Lang::get('drift-alerts::threshold.toast_updated'));
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('drift-alerts::livewire.drift-threshold-editor', [
            'currentValue' => $this->currentValue,
            'options' => self::OPTIONS,
        ]);
    }
}
