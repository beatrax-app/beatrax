@use('Modules\Core\Public\Support\Lang')
{{--
    Shared deduction-category picker. Rendered ONCE per surface component
    (not per row). Reads the host component's Livewire properties via $this:
      - taxPickerTxId       ?int   — which transaction is being tagged (null = closed)
      - pickerNote          string — free-text note
      - pickerCategoryId    ?int   — selected deduction category id
      - pickerYearOverride  ?int   — manual year assignment
      - pickerCategories    list<\stdClass>  — active categories for the picker list
      - pickerInlineNewName string — quick-add new-category name input
      - pickerIsNewCatOpen  bool   — whether the inline new-category row is expanded
      - batchSuggestion     ?array — batch-tag banner data (counterpartyName, untaggedCount)
      - batchSuggestionDismissed bool

    Desktop: anchored popover (Alpine x-data open/close + click-outside dismiss).
    Phone (<768px): bottom sheet with handle bar + backdrop scrim.

    The host component must use HandlesTaxTagging to provide these props.
--}}
<div
    x-data="{
        open: false,
        init() {
            this.$watch(() => $wire.taxPickerTxId, (v) => { this.open = (v !== null && v !== undefined); });
        },
        closeAll() {
            this.open = false;
            $wire.closePicker();
        }
    }"
    x-on:keydown.escape.window="if (open) { closeAll(); }"
>
    {{-- ===== DESKTOP POPOVER ===== --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-on:click.outside="closeAll()"
        class="hidden md:block"
        role="dialog"
        aria-modal="true"
        aria-label="{{ Lang::get('tax::picker.dialog_aria') }}"
        style="
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 50;
            background: var(--color-surface-raised, #fff);
            border: 1px solid var(--color-border-strong, #cbd5e1);
            border-radius: var(--radius-lg, 0.75rem);
            box-shadow: var(--shadow-md, 0 4px 6px -1px rgb(0 0 0 / 0.1));
            min-width: 220px;
            max-width: 320px;
            padding: var(--space-4, 1rem);
            width: 100%;
        "
    >
        @include('tax::components.tax-tag-popover-body')
    </div>

    {{-- ===== PHONE BOTTOM SHEET ===== --}}
    <div
        x-show="open"
        x-cloak
        class="md:hidden"
    >
        {{-- Backdrop scrim --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            x-on:click="closeAll()"
            style="position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 40;"
            aria-hidden="true"
        ></div>

        {{-- Sheet panel --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="transform translate-y-full"
            x-transition:enter-end="transform translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="transform translate-y-0"
            x-transition:leave-end="transform translate-y-full"
            role="dialog"
            aria-modal="true"
            aria-label="{{ Lang::get('tax::picker.dialog_aria') }}"
            style="
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 50;
                background: var(--color-surface-raised, #fff);
                border-radius: 12px 12px 0 0;
                max-height: 70vh;
                overflow-y: auto;
                padding: var(--space-4, 1rem);
                padding-bottom: calc(var(--space-4, 1rem) + env(safe-area-inset-bottom, 0px));
            "
        >
            {{-- Handle bar --}}
            <div style="display: flex; justify-content: center; margin-bottom: var(--space-3);">
                <div style="width: 32px; height: 4px; background: var(--color-border-strong, #94a3b8); border-radius: 9999px;"></div>
            </div>

            @include('tax::components.tax-tag-popover-body')
        </div>
    </div>

    {{-- ===== BATCH-TAG BANNER ===== --}}
    @if (
        ($batchSuggestion['untaggedCount'] ?? 0) >= 2
        && ! ($batchSuggestionDismissed ?? false)
        && $taxPickerTxId === null
    )
        <div
            class="batch-tag-banner"
            aria-atomic="true"
            aria-live="polite"
            data-testid="batch-tag-banner"
            style="margin-top: var(--space-3);"
        >
            <span>
                {{ Lang::get('tax::picker.batch_before', ['count' => $batchSuggestion['untaggedCount']]) }} <strong>{{ $batchSuggestion['counterpartyName'] }}</strong>{{ Lang::get('tax::picker.batch_after') }}
            </span>
            <div style="display: flex; gap: var(--space-2); margin-left: auto;">
                <button
                    type="button"
                    wire:click="applyBatchTag"
                    style="background: var(--color-blue, #3b82f6); color: #fff; border: 0; border-radius: 9999px; padding: 4px 12px; font-size: var(--text-xs, 12px); font-weight: 600; cursor: pointer; min-height: 32px;"
                    data-testid="batch-tag-apply"
                >{{ Lang::get('tax::picker.batch_tag_all') }}</button>
                <button
                    type="button"
                    wire:click="dismissBatch"
                    style="background: transparent; border: 0; color: var(--color-text-muted, #64748b); font-size: var(--text-xs, 12px); cursor: pointer; min-height: 32px;"
                    data-testid="batch-tag-dismiss"
                >{{ Lang::get('tax::picker.batch_dismiss') }}</button>
            </div>
        </div>
    @endif
</div>
