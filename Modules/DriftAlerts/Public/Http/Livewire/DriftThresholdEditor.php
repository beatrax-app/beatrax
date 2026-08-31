<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\DriftThresholdOptions;
use Modules\Core\Public\Support\Lang;
use Modules\Recurring\Public\Actions\SetDriftThresholdForSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

final class DriftThresholdEditor extends Component
{
    use DispatchesToast;

    /** @var list<int> */
    public const array OPTIONS = DriftThresholdOptions::PERCENTS;

    // Locked because mount() proves nothing about it: on /drift the parent
    // passes currentValueLoaded, so the ownership-bearing read is skipped
    // entirely. Unlocked, a payload naming another series wrote that series'
    // drift threshold through save() and replicated it to paired devices.
    #[Locked]
    public int $recurringSeriesId = 0;

    public ?int $currentValue = null;

    // A parent that renders one editor per alert group already holds the whole
    // column and says so with $currentValueLoaded; null on its own cannot, since
    // null is also the answer for a series that follows the global default. A
    // page that mounts this alone passes neither and reads its own row.
    public function mount(
        int $recurringSeriesId,
        CurrentUser $currentUser,
        RecurringSeriesQuery $series,
        ?int $currentValue = null,
        bool $currentValueLoaded = false,
    ): void {
        $this->recurringSeriesId = $recurringSeriesId;

        if ($currentValueLoaded) {
            $this->currentValue = $currentValue;

            return;
        }

        $this->currentValue = $series->driftThresholdForSeries($recurringSeriesId, $currentUser->user());
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
