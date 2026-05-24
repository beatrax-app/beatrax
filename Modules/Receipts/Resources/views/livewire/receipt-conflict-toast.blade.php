<div>
    @if ($visible)
        @php
            // Per-field copy mapping. The receipt-conflict policy is
            // field-agnostic at the data layer (any of the four
            // FingerprintStage fields can disagree), but the user
            // wants to read what actually disagreed — saying "cleaner
            // merchant name (1299)" for an amount conflict is wrong.
            $fieldLabel = match ($field) {
                'amount_minor' => 'amount',
                'currency' => 'currency',
                'description' => 'description',
                'counterparty_name' => 'merchant name',
                default => 'value',
            };
            $heading = $field === 'counterparty_name'
                ? 'An email receipt has a cleaner '.$fieldLabel
                : 'An email receipt records a different '.$fieldLabel;
        @endphp
        <div
            role="alert"
            aria-live="assertive"
            class="fixed bottom-md right-md z-50 max-w-sm rounded-lg border border-slate-200 bg-white shadow-lg p-md dark:bg-slate-950 dark:border-slate-700"
        >
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                Receipt and statement disagree.
            </div>
            <p class="mt-1 text-sm text-slate-700 dark:text-slate-300">
                {{ $heading }} (&ldquo;{{ $receiptValue ?? '' }}&rdquo;)
                than the statement (&ldquo;{{ $csvValue ?? '' }}&rdquo;).
                Should beatrax prefer receipts for future conflicts?
            </p>
            <div class="mt-md flex items-center gap-sm">
                <button
                    type="button"
                    wire:click="useReceipt"
                    class="text-sm font-medium text-emerald-700 hover:bg-emerald-50 px-2 py-1 rounded-md dark:text-emerald-200 dark:hover:bg-emerald-950"
                >
                    Use receipt
                </button>
                <button
                    type="button"
                    wire:click="keepStatement"
                    class="text-sm font-medium text-slate-700 hover:bg-slate-50 px-2 py-1 rounded-md dark:text-slate-300 dark:hover:bg-slate-900"
                >
                    Keep statement
                </button>
            </div>
        </div>
    @endif
</div>
