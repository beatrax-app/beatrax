@use('Modules\Core\Public\Support\Iban')
{{--
    Reusable counterparty-column row partial. Renders either the plain
    aliasFriendlyName when the resolver populated one OR the italic
    .desc-fallback span carrying the raw description. The italic span
    is the click target — Livewire dispatches `rename-counterparty:open`
    with `raw` + `rowIndex` so the popover (mounted at the bottom of
    the wizard blade) can open against this row.

    The partial is rendered only by the RenameCounterpartyPopoverTest
    .desc-fallback / friendly-name assertions, which exercise both
    branches without standing up the full preview pipeline.
    preview-wizard.blade.php carries its own copy of this fallback
    chain inline and does not include this file.

    Expects `$rows` (list<PreviewRowDto>) in scope.

    UI-SPEC §19: overflow-x:auto on the outer wrapper ensures
    counterparty name cells scroll horizontally at phone width when
    this partial is rendered standalone (e.g. in the test context).
    When embedded inside the preview-wizard table the parent section's
    overflow-x-auto handles the horizontal scroll.
--}}

@use('Modules\Core\Public\Support\Lang')
{{-- overflow-x-auto ensures phone-width horizontal scroll in standalone use --}}
<div class="overflow-x-auto">
    @foreach ($rows as $row)
        <div data-row-index="{{ $row->rowIndex }}">
            @if ($row->aliasFriendlyName !== null)
                <span>{{ $row->aliasFriendlyName }}</span>
            @elseif ($row->counterpartyName !== null)
                <span>{{ $row->counterpartyName }}</span>
            @elseif ($row->counterpartyIban !== null)
                <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ Iban::grouped($row->counterpartyIban) }}</span>
            @elseif ($row->description !== null)
                <button
                    type="button"
                    class="desc-fallback"
                    aria-label="{{ Lang::get('import::preview.rename_aria') }}"
                    wire:click="$dispatch('rename-counterparty:open', { raw: @js($row->description), rowIndex: {{ $row->rowIndex }} })"
                >{{ $row->description }}</button>
            @else
                <span class="text-slate-600 dark:text-slate-400">—</span>
            @endif
        </div>
    @endforeach
</div>
