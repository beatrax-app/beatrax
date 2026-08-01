@use('Modules\Core\Public\Support\Lang')
<div>
    @if ($visible)
        @php
            // Per-field copy mapping. The receipt-conflict policy is
            // field-agnostic at the data layer (any of the four
            // FingerprintStage fields can disagree), but the user
            // wants to read what actually disagreed — saying "cleaner
            // merchant name (1299)" for an amount conflict is wrong.
            $fieldLabel = match ($field) {
                'amount_minor' => Lang::get('receipts::messages.conflict.field.amount_minor'),
                'currency' => Lang::get('receipts::messages.conflict.field.currency'),
                'description' => Lang::get('receipts::messages.conflict.field.description'),
                'counterparty_name' => Lang::get('receipts::messages.conflict.field.counterparty_name'),
                default => Lang::get('receipts::messages.conflict.field.default'),
            };
            $heading = $field === 'counterparty_name'
                ? Lang::get('receipts::messages.conflict.heading_cleaner', ['field' => $fieldLabel])
                : Lang::get('receipts::messages.conflict.heading_different', ['field' => $fieldLabel]);
        @endphp
        <div
            role="alert"
            aria-live="assertive"
            class="fixed bottom-md right-md z-50 max-w-sm rounded-lg border border-slate-200 bg-white shadow-lg p-md dark:bg-slate-950 dark:border-slate-700"
        >
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                {{ Lang::get('receipts::messages.conflict.title') }}
            </div>
            <p class="mt-1 text-sm text-slate-700 dark:text-slate-300">
                {{ Lang::get('receipts::messages.conflict.body', ['heading' => $heading, 'receipt' => $receiptValue ?? '', 'statement' => $csvValue ?? '']) }}
            </p>
            <div class="mt-md flex items-center gap-sm">
                <button
                    type="button"
                    wire:click="useReceipt"
                    class="text-sm font-medium text-emerald-700 hover:bg-emerald-50 px-2 py-1 rounded-md dark:text-emerald-200 dark:hover:bg-emerald-950"
                >
                    {{ Lang::get('receipts::messages.conflict.use_receipt') }}
                </button>
                <button
                    type="button"
                    wire:click="keepStatement"
                    class="text-sm font-medium text-slate-700 hover:bg-slate-50 px-2 py-1 rounded-md dark:text-slate-300 dark:hover:bg-slate-900"
                >
                    {{ Lang::get('receipts::messages.conflict.keep_statement') }}
                </button>
            </div>
        </div>
    @endif
</div>
