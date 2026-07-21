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
 * @link ../../../../../.docs/features/drift-alerts/architecture.md
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
