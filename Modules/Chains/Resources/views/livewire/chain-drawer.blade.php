@use('Modules\Core\Public\Support\Lang')
{{-- Chain drill-down drawer.

     Project's first Flux flyout. The Livewire SFC dispatches
     `chain-drawer:open` (from the TransactionDetail "View chain"
     button) to set $transactionId; the Flux modal opens on the same
     event via a paired Alpine listener on the trigger button.

     The view receives:
       - $tree: ?ChainTree   (null = pre-mount / not yet resolved)
       - $fanoutPage: int    (explicit context; the
                              child partial declares matching @props
                              so the binding contract is visible at
                              both ends)
       - $actionError: ?string (a chip acted on a link the other tab
                              had already decided).

     The sticky header carries `sticky top-0 bg-white z-10` per
     UI-SPEC § Interaction Contracts; the drawer body itself never
     scrolls. Long ICS bulk-settle fan-outs paginate INSIDE
     each fan-out container via the "Show 10 more · X of N" affordance
     rendered in the chain-node partial. --}}

<div>
    {{-- Stable modal name. The earlier draft tied the name to
         `$tree?->rootTransactionId ?? 0` — which is `0` until the
         wire round-trip that loads the tree completes. The trigger
         button's Alpine `$dispatch('modal-show', { name: 'chain-
         drawer-{tx_id}' })` fires immediately on click and never
         matched the pre-load modal name → the modal silently did
         nothing on first click. Pinning the name to the literal
         string `chain-drawer` removes the race. The component
         already singleton-scopes per request via `$transactionId`,
         so one drawer instance serves every row. --}}
    <flux:modal name="chain-drawer" flyout position="right" class="md:w-2xl">
        <flux:heading size="lg" class="sticky top-0 bg-white z-10 pb-3 -mx-6 px-6 dark:bg-slate-950">
            @if ($tree !== null && count($tree->nodes) > 0 && $tree->nodes[0]->counterpartyName !== '')
                {{ Lang::get('chains::drawer.heading_named', ['name' => $tree->nodes[0]->counterpartyName]) }}
            @elseif ($tree !== null && count($tree->nodes) > 0 && $tree->nodes[0]->accountName !== '')
                {{ Lang::get('chains::drawer.heading_named', ['name' => $tree->nodes[0]->accountName]) }}
            @else
                {{ Lang::get('chains::drawer.heading') }}
            @endif
        </flux:heading>

        @if ($actionError)
            <x-core::alert
                tone="danger"
                class="mx-6 mb-md"
                aria-live="polite" aria-atomic="true"
                data-testid="chain-drawer-action-error"
            >
                {{ $actionError }}
            </x-core::alert>
        @endif

        @if ($tree === null)
            <x-core::empty-state
                level="h3"
                class="mx-6 mt-md"
                :heading="Lang::get('chains::drawer.unresolved_heading')"
                :body="Lang::get('chains::drawer.unresolved_body')"
            />
        @elseif (count($tree->nodes) === 0)
            <x-core::empty-state
                level="h3"
                class="mx-6 mt-md"
                :heading="Lang::get('chains::drawer.none_heading')"
                :body="Lang::get('chains::drawer.none_body')"
            />
        @elseif (count($tree->nodes) === 1)
            {{-- Only the root node walked back — no funder leg followed. --}}
            {{-- overflow-x-scroll-wrapper ensures chain-node inner content scrolls horizontally at phone width --}}
            <div class="overflow-x-scroll-wrapper px-6 py-md space-y-md" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                @include('chains::livewire.partials.chain-node', ['node' => $tree->nodes[0], 'fanoutPage' => $fanoutPage])
                <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:bg-slate-900 dark:border-slate-700">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('chains::drawer.none_beyond_leg') }}</p>
                </div>
            </div>
        @else
            {{-- overflow-x-scroll-wrapper ensures multi-leg chain content scrolls horizontally at phone width --}}
            <div class="overflow-x-scroll-wrapper px-6 py-md space-y-md" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                @foreach ($tree->nodes as $node)
                    {{-- $fanoutPage is passed explicitly: the partial
                         declares @props(['node', 'fanoutPage']) at its
                         top so the binding contract is portable and
                         obvious — no implicit parent-scope inheritance. --}}
                    @include('chains::livewire.partials.chain-node', ['node' => $node, 'fanoutPage' => $fanoutPage])
                @endforeach
            </div>
        @endif
    </flux:modal>
</div>
