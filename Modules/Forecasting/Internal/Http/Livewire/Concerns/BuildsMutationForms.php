<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire\Concerns;

use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Enums\ScenarioFormField;
use Modules\Forecasting\Internal\Support\AmountStringParser;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Forecasting\Public\Enums\ShiftScope;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Enums\SeriesCadence;

// The mutation-form surface of ScenarioEditorSidebar: the per-kind default
// form shape, the typed payload it builds, and the field coercion helpers
// the two share. Split out so the component stays under the method ceiling
// while these stay one cohesive unit.
trait BuildsMutationForms
{
    /**
     * @return array<string, mixed>
     */
    private function defaultFormFor(string $kind, string $baseCurrency): array
    {
        return match ($kind) {
            ScenarioMutationKind::CancelSeries->value => ['seriesId' => null],
            ScenarioMutationKind::AddOneOff->value => ['date' => '', 'amount' => '', 'currency' => $baseCurrency, 'direction' => Direction::Expense->value, 'note' => ''],
            ScenarioMutationKind::AddRecurring->value => ['startDate' => '', 'amount' => '', 'currency' => $baseCurrency, 'direction' => Direction::Expense->value, 'cadence' => SeriesCadence::Monthly->value, 'note' => ''],
            ScenarioMutationKind::ChangeSeriesAmount->value => ['seriesId' => null, 'newAmount' => ''],
            ScenarioMutationKind::ShiftSeriesDate->value => ['seriesId' => null, 'newNextDate' => '', 'scope' => ShiftScope::Next->value],
            default => [],
        };
    }

    private function buildPayloadFromForm(string $kind, string $baseCurrency): ?ScenarioMutationPayload
    {
        try {
            return match ($kind) {
                ScenarioMutationKind::CancelSeries->value => new CancelSeriesPayload(
                    seriesId: $this->intField(ScenarioFormField::SeriesId),
                ),
                ScenarioMutationKind::AddOneOff->value => $this->oneOffFromForm($baseCurrency),
                ScenarioMutationKind::AddRecurring->value => $this->recurringFromForm($baseCurrency),
                ScenarioMutationKind::ChangeSeriesAmount->value => $this->changeAmountFromForm($baseCurrency),
                ScenarioMutationKind::ShiftSeriesDate->value => new ShiftSeriesDatePayload(
                    seriesId: $this->intField(ScenarioFormField::SeriesId),
                    newNextDate: $this->stringField(ScenarioFormField::NewNextDate),
                    scope: $this->stringField(ScenarioFormField::Scope, ShiftScope::Next->value),
                ),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            $this->formError = $e->getMessage();

            return null;
        }
    }

    // The typed figure is denominated in the code the form carries, so the
    // amount is read at that currency's own scale: 5000 in a yen box is
    // JPY5,000, and the repo-wide two decimals stored JPY500,000.
    private function oneOffFromForm(string $baseCurrency): AddOneOffPayload
    {
        $currency = $this->stringField(ScenarioFormField::Currency, $baseCurrency);

        return new AddOneOffPayload(
            date: $this->stringField(ScenarioFormField::Date),
            amountMinor: $this->parseAmountMinor(ScenarioFormField::Amount, $currency),
            currency: $currency,
            direction: $this->stringField(ScenarioFormField::Direction, Direction::Expense->value),
            note: $this->optionalStringField(ScenarioFormField::Note),
        );
    }

    private function recurringFromForm(string $baseCurrency): AddRecurringPayload
    {
        $currency = $this->stringField(ScenarioFormField::Currency, $baseCurrency);

        return new AddRecurringPayload(
            startDate: $this->stringField(ScenarioFormField::StartDate),
            amountMinor: $this->parseAmountMinor(ScenarioFormField::Amount, $currency),
            currency: $currency,
            direction: $this->stringField(ScenarioFormField::Direction, Direction::Expense->value),
            cadence: $this->stringField(ScenarioFormField::Cadence, SeriesCadence::Monthly->value),
            note: $this->optionalStringField(ScenarioFormField::Note),
        );
    }

    // A new amount for a series is denominated in that series' own currency,
    // never the reader's base one.
    private function changeAmountFromForm(string $baseCurrency): ChangeSeriesAmountPayload
    {
        $seriesId = $this->intField(ScenarioFormField::SeriesId);

        return new ChangeSeriesAmountPayload(
            seriesId: $seriesId,
            newAmountMinor: $this->parseAmountMinor(
                ScenarioFormField::NewAmount,
                $this->currencyForSeries($seriesId, $baseCurrency),
            ),
        );
    }

    private function currencyForSeries(int $seriesId, string $baseCurrency): string
    {
        return $this->seriesCurrency($seriesId) ?? $baseCurrency;
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

    private function intField(ScenarioFormField $field): int
    {
        $value = $this->form[$field->value] ?? null;
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(self::requiredMessage($field));
        }

        return (int) $value;
    }

    private function stringField(ScenarioFormField $field, ?string $default = null): string
    {
        $value = $this->form[$field->value] ?? $default;
        if (! is_string($value) || $value === '') {
            if ($default !== null) {
                return $default;
            }
            throw new \InvalidArgumentException(self::requiredMessage($field));
        }

        return $value;
    }

    private function optionalStringField(ScenarioFormField $field): ?string
    {
        $value = $this->form[$field->value] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function parseAmountMinor(ScenarioFormField $field, string $currency): int
    {
        $value = $this->form[$field->value] ?? null;
        if (is_string($value)) {
            $raw = $value;
        } elseif (is_numeric($value)) {
            $raw = (string) $value;
        } else {
            throw new \InvalidArgumentException(Lang::get('forecasting::forecast.errors.amount_required'));
        }

        return AmountStringParser::toMinor($raw, $currency);
    }

    private static function requiredMessage(ScenarioFormField $field): string
    {
        return Lang::get('forecasting::forecast.errors.field_required', [
            'field' => Lang::get($field->labelKey()),
        ]);
    }
}
