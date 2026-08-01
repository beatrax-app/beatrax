@use('Modules\Core\Public\Support\Lang')
{{--
    Settings "Anomaly detection" section (D-11/D-18). Three sub-sections:
    Sensitivity, Minimum charge amount, and the user-visible + removable
    Suppression rules list — nothing is muted invisibly.

    Variables in scope:
      - $anomalySensitivityPercent : int (1–100)
      - $anomalyMinAmountMinor : int (minor units, >= 0)
      - $suppressionRules : list<AnomalySuppressionRuleDto>
      - $saveError : string
      - $saved : bool

    Blade default `{{ }}` escaping for every interpolation.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $ruleFmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');

    $detectorLabels = [
        'large' => Lang::get('anomaly::settings.detectors.large'),
        'first_time' => Lang::get('anomaly::settings.detectors.first_time'),
        'duplicate' => Lang::get('anomaly::settings.detectors.duplicate'),
    ];
@endphp

<div class="space-y-6" data-testid="anomaly-settings-section">
    <form wire:submit="save" class="space-y-6">
        {{-- Sensitivity --}}
        <div class="space-y-1">
            <label for="anomalySensitivityPercent" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('anomaly::settings.sensitivity_label') }}</label>
            <input
                type="number"
                min="1"
                max="100"
                id="anomalySensitivityPercent"
                name="anomalySensitivityPercent"
                wire:model="anomalySensitivityPercent"
                style="font-variant-numeric: tabular-nums;"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
            />
            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ Lang::get('anomaly::settings.sensitivity_help', ['percent' => $anomalySensitivityPercent]) }}
            </p>
        </div>

        {{-- Minimum charge amount floor --}}
        <div class="space-y-1">
            <label for="anomalyMinAmountMinor" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('anomaly::settings.min_amount_label') }}</label>
            <input
                type="number"
                min="0"
                step="1"
                id="anomalyMinAmountMinor"
                name="anomalyMinAmountMinor"
                wire:model="anomalyMinAmountMinor"
                style="font-variant-numeric: tabular-nums;"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
            />
            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ Lang::get('anomaly::settings.min_amount_help') }}
            </p>
        </div>

        @if ($saveError !== '')
            <p class="text-sm text-rose-600 dark:text-rose-500" data-testid="anomaly-save-error">{{ $saveError }}</p>
        @endif

        <button
            type="submit"
            class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
        >{{ Lang::get('anomaly::settings.save') }}</button>

        @if ($saved)
            <p wire:transition.duration.4000ms class="text-sm text-emerald-700 dark:text-emerald-400" data-testid="anomaly-saved">{{ Lang::get('anomaly::settings.saved') }}</p>
        @endif
    </form>

    {{-- Suppression rules (D-18) — collapsible, visible, removable. --}}
    <details class="mt-2" data-testid="suppression-rules">
        <summary class="cursor-pointer text-sm font-medium text-slate-900 dark:text-slate-100">
            {{ Lang::get('anomaly::settings.suppression.summary') }}
            @if (count($suppressionRules) > 0)
                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300" style="font-variant-numeric: tabular-nums;">{{ count($suppressionRules) }}</span>
            @endif
        </summary>
        @if (count($suppressionRules) === 0)
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400" data-testid="suppression-rules-empty">
                {{ Lang::get('anomaly::settings.suppression.empty') }}
            </p>
        @else
            <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-800" role="list">
                @foreach ($suppressionRules as $rule)
                    <li class="flex items-center justify-between gap-3 py-2" data-testid="suppression-rule-{{ $rule->id }}">
                        <span class="min-w-0 flex-1 truncate text-sm text-slate-700 dark:text-slate-300" style="font-variant-numeric: tabular-nums;">
                            {{ $rule->displayName !== '' ? $rule->displayName : Lang::get('anomaly::settings.unknown_merchant') }}
                            <span class="mx-1 text-slate-400">·</span>{{ $detectorLabels[$rule->detector] ?? ucwords(str_replace('_', ' ', $rule->detector)) }}
                            <span class="mx-1 text-slate-400">·</span>{{ $ruleFmt($rule->bandLow) }} – {{ $ruleFmt($rule->bandHigh) }}
                        </span>
                        <button
                            type="button"
                            wire:click="removeSuppressionRule({{ $rule->id }})"
                            aria-label="{{ Lang::get('anomaly::settings.suppression.remove_aria') }}"
                            class="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-slate-500 hover:text-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-rose-400"
                        >{{ Lang::get('anomaly::settings.suppression.remove') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </details>
</div>
