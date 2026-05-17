<div>
    @if ($visible)
        <div
            role="alert"
            aria-live="assertive"
            class="fixed bottom-md right-md z-50 max-w-sm rounded-lg border border-slate-200 bg-white shadow-lg p-md"
        >
            <div class="text-sm font-semibold text-slate-900">
                Receipt and statement disagree.
            </div>
            <p class="mt-1 text-sm text-slate-700">
                An email receipt has a cleaner merchant name (&ldquo;{{ $receiptValue ?? '' }}&rdquo;)
                than the statement (&ldquo;{{ $csvValue ?? '' }}&rdquo;).
                Should diederik prefer receipts for future conflicts?
            </p>
            <div class="mt-md flex items-center gap-sm">
                <button
                    type="button"
                    wire:click="useReceipt"
                    class="text-sm font-medium text-emerald-700 hover:bg-emerald-50 px-2 py-1 rounded-md"
                >
                    Use receipt
                </button>
                <button
                    type="button"
                    wire:click="keepStatement"
                    class="text-sm font-medium text-slate-700 hover:bg-slate-50 px-2 py-1 rounded-md"
                >
                    Keep statement
                </button>
            </div>
        </div>
    @endif
</div>
