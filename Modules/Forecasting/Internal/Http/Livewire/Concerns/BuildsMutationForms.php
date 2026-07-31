<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire\Concerns;

use Modules\Forecasting\Internal\Support\AmountStringParser;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Enums\ShiftScope;
use Modules\Recurring\Public\Enums\SeriesCadence;

// The mutation-form surface of ScenarioEditorSidebar: the per-kind default
// form shape, the typed payload it builds, and the field coercion helpers
// the two share. Split out so the component stays under the method ceiling
// while these stay one cohesive unit.
/**
 * @link ../../../../../../.docs/features/forecasting/architecture.md
 */
trait BuildsMutationForms
{
    /**
     * @return array<string, mixed>
     */
    private function defaultFormFor(string $kind): array
    {
        return match ($kind) {
            'cancel_series' => ['seriesId' => null],
            'add_one_off' => ['date' => '', 'amount' => '', 'currency' => 'EUR', 'direction' => 'expense', 'note' => ''],
            'add_recurring' => ['startDate' => '', 'amount' => '', 'currency' => 'EUR', 'direction' => 'expense', 'cadence' => SeriesCadence::Monthly->value, 'note' => ''],
            'change_series_amount' => ['seriesId' => null, 'newAmount' => ''],
            'shift_series_date' => ['seriesId' => null, 'newNextDate' => '', 'scope' => ShiftScope::Next->value],
            default => [],
        };
    }

    private function buildPayloadFromForm(string $kind): ?ScenarioMutationPayload
    {
        try {
            return match ($kind) {
                'cancel_series' => new CancelSeriesPayload(
                    seriesId: $this->intField('seriesId'),
                ),
                'add_one_off' => new AddOneOffPayload(
                    date: $this->stringField('date'),
                    amountMinor: $this->parseAmountMinor('amount'),
                    currency: $this->stringField('currency', 'EUR'),
                    direction: $this->stringField('direction', 'expense'),
                    note: $this->optionalStringField('note'),
                ),
                'add_recurring' => new AddRecurringPayload(
                    startDate: $this->stringField('startDate'),
                    amountMinor: $this->parseAmountMinor('amount'),
                    currency: $this->stringField('currency', 'EUR'),
                    direction: $this->stringField('direction', 'expense'),
                    cadence: $this->stringField('cadence', 'monthly'),
                    note: $this->optionalStringField('note'),
                ),
                'change_series_amount' => new ChangeSeriesAmountPayload(
                    seriesId: $this->intField('seriesId'),
                    newAmountMinor: $this->parseAmountMinor('newAmount'),
                ),
                'shift_series_date' => new ShiftSeriesDatePayload(
                    seriesId: $this->intField('seriesId'),
                    newNextDate: $this->stringField('newNextDate'),
                    scope: $this->stringField('scope', ShiftScope::Next->value),
                ),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            $this->formError = $e->getMessage();

            return null;
        }
    }

    /**
     * @param  mixed  $payload  the persisted mutation payload, coerced to a
     *                          string-keyed form array
     * @return array<string, mixed>
     */
    private function coercePayloadForm(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }
        $coerced = [];
        foreach ($payload as $k => $v) {
            if (is_string($k)) {
                $coerced[$k] = $v;
            }
        }

        return $coerced;
    }

    private function intField(string $key): int
    {
        $value = $this->form[$key] ?? null;
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("Field '{$key}' is required.");
        }

        return (int) $value;
    }

    private function stringField(string $key, ?string $default = null): string
    {
        $value = $this->form[$key] ?? $default;
        if (! is_string($value) || $value === '') {
            if ($default !== null) {
                return $default;
            }
            throw new \InvalidArgumentException("Field '{$key}' is required.");
        }

        return $value;
    }

    private function optionalStringField(string $key): ?string
    {
        $value = $this->form[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function parseAmountMinor(string $key): int
    {
        $value = $this->form[$key] ?? null;
        if (is_string($value)) {
            $raw = $value;
        } elseif (is_numeric($value)) {
            $raw = (string) $value;
        } else {
            throw new \InvalidArgumentException('Amount is required.');
        }

        return AmountStringParser::toMinor($raw);
    }
}
