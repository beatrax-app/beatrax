@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Reports\Internal\Actions\TogglePin')
{{--
    `/reports/library` saved-report index — Cards|List CRUD:
    list/open/edit/delete + pin toggle. Cards-default grid mirroring
    `/counterparties` (999.6-PATTERNS.md "ReportsIndex.php" /
    999.6-UI-SPEC.md Component Inventory item 3).

    Variables exposed by ReportsIndex::render():
      $rows         Illuminate\Support\Collection<SavedReportIndexRow>
      $activeView   'cards' | 'list'
      $pinnedCount  int  — count of $rows where pinned === true
--}}
<div class="space-y-8" style="padding: var(--space-6) var(--space-4); max-width: 1200px; margin: 0 auto;">
    {{-- Page head ------------------------------------------------- --}}
    <header class="space-y-2">
        <h1 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin: 0;">
            {{ Lang::get('reports::index.title') }}
        </h1>
        <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0; font-variant-numeric: tabular-nums;">
            {{ Lang::choice('reports::index.saved_report', $rows->count()) }} · {{ Lang::choice('reports::index.pinned_count', $pinnedCount, ['max' => TogglePin::MAX_PINS]) }}
        </p>
    </header>

    @if ($flashMessage !== '')
        <div
            aria-atomic="true"
            aria-live="polite"
            class="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"
            style="border-color: var(--color-rose); background: var(--color-rose-bg); color: var(--color-rose);"
        >
            <span>{{ $flashMessage }}</span>
            <x-core::emoji-action
                :label="Lang::get('reports::index.dismiss')"
                wire:click="clearFlash"
            >✖️</x-core::emoji-action>
        </div>
    @endif

    {{-- Toolbar: New report link + Cards|List toggle ---------------- --}}
    <div style="display: flex; align-items: center; justify-content: space-between; gap: var(--space-4); flex-wrap: wrap;">
        <a href="{{ Destination::Reports->url() }}" class="pill-btn-primary focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900" style="text-decoration: none;">
            {{ Lang::get('reports::index.build_new') }}
        </a>

        @if ($rows->isNotEmpty())
            <div class="view-toggle" role="group" aria-label="{{ Lang::get('reports::index.view_mode_aria') }}">
                <button
                    type="button"
                    class="{{ $activeView === 'cards' ? 'active' : '' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                    aria-pressed="{{ $activeView === 'cards' ? 'true' : 'false' }}"
                    wire:click="setView('cards')"
                >▦ {{ Lang::get('reports::index.cards') }}</button>
                <button
                    type="button"
                    class="{{ $activeView === 'list' ? 'active' : '' }} focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                    aria-pressed="{{ $activeView === 'list' ? 'true' : 'false' }}"
                    wire:click="setView('list')"
                >≡ {{ Lang::get('reports::index.list') }}</button>
            </div>
        @endif
    </div>

    {{-- Body ------------------------------------------------------- --}}
    @if ($rows->isEmpty())
        <x-core::empty-state
            :heading="Lang::get('reports::index.empty.heading')"
            :body="Lang::get('reports::index.empty.body')"
        >
            <a href="{{ Destination::Reports->url() }}" class="pill-btn-primary focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900" style="display: inline-block; text-decoration: none;">
                {{ Lang::get('reports::index.empty.cta') }}
            </a>
        </x-core::empty-state>
    @elseif ($activeView === 'cards')
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--space-4);" class="cp-cards-grid">
            @foreach ($rows as $row)
                <div class="cp-card group" style="position: relative;" data-report-card="{{ $row->id }}">
                    <header class="cp-head" style="justify-content: space-between;">
                        <span class="cp-head-name">{{ $row->name }}</span>
                        <button
                            type="button"
                            wire:click="togglePin({{ $row->id }})"
                            aria-pressed="{{ $row->pinned ? 'true' : 'false' }}"
                            aria-label="{{ $row->pinned ? Lang::get('reports::index.pin.pinned_aria') : Lang::get('reports::index.pin.pin_aria') }}"
                            title="{{ $row->pinned ? Lang::get('reports::index.pin.pinned_title') : Lang::get('reports::index.pin.pin_title') }}"
                            class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                            style="{{ $row->pinned ? 'background: var(--color-text); color: var(--color-text-inverse); border-color: var(--color-text);' : '' }}"
                        ><span aria-hidden="true">📌</span> {{ $row->pinned ? Lang::get('reports::index.pin.pinned_label') : Lang::get('reports::index.pin.pin_label') }}</button>
                    </header>

                    <p class="cp-recent" style="margin: 0;">{{ $row->summary }}</p>

                    {{-- The action row is hover-revealed, and the confirm strip
                         used to live inside it: asking the question then fading
                         it out as soon as the pointer left the card. The strip
                         replaces the row instead, so the answer stays visible. --}}
                    @if ($confirmingDeleteId === $row->id)
                        <x-core::confirm-strip
                            style="margin-top: auto;"
                            :question="Lang::get('reports::index.delete_confirm', ['name' => $row->name])"
                            :cancel-label="Lang::get('reports::index.cancel')"
                            :confirm-label="Lang::get('reports::index.delete_report')"
                            cancel="cancelDelete"
                            :confirm="'deleteReport('.$row->id.')'"
                        />
                    @else
                        <div
                            class="opacity-0 group-hover:opacity-100 transition-opacity"
                            style="display: flex; align-items: center; gap: var(--space-2); margin-top: auto; padding-top: var(--space-2);"
                        >
                            <a href="{{ Destination::Reports->url(['report' => $row->id]) }}" class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900" style="text-decoration: none;">{{ Lang::get('reports::index.open') }}</a>
                            <a href="{{ Destination::Reports->url(['report' => $row->id]) }}" class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900" style="text-decoration: none;">{{ Lang::get('reports::index.edit') }}</a>

                            <button
                                type="button"
                                wire:click="confirmDelete({{ $row->id }})"
                                aria-label="{{ Lang::get('reports::index.delete_aria', ['name' => $row->name]) }}"
                                class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                                style="color: var(--color-rose);"
                            >{{ Lang::get('reports::index.delete') }}</button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        {{-- List view ------------------------------------------------ --}}
        <div class="frame" style="padding: 0; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead style="background: var(--color-surface-2); text-align: left;">
                    <tr style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-muted);">
                        <th style="padding: var(--space-2) var(--space-3);">{{ Lang::get('reports::index.col.name') }}</th>
                        <th style="padding: var(--space-2) var(--space-3);">{{ Lang::get('reports::index.col.summary') }}</th>
                        <th style="padding: var(--space-2) var(--space-3);">{{ Lang::get('reports::index.col.pinned') }}</th>
                        <th style="padding: var(--space-2) var(--space-3); text-align: right;"><span class="sr-only">{{ Lang::get('reports::index.col.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr style="border-top: 1px solid var(--color-border);">
                            <td style="padding: var(--space-2) var(--space-3);">
                                <a href="{{ Destination::Reports->url(['report' => $row->id]) }}" style="color: var(--color-text); text-decoration: none; font-weight: 600;">
                                    {{ $row->name }}
                                </a>
                            </td>
                            <td style="padding: var(--space-2) var(--space-3); color: var(--color-text-muted); font-size: var(--text-sm);">
                                {{ $row->summary }}
                            </td>
                            <td style="padding: var(--space-2) var(--space-3);">
                                <button
                                    type="button"
                                    wire:click="togglePin({{ $row->id }})"
                                    aria-pressed="{{ $row->pinned ? 'true' : 'false' }}"
                                    aria-label="{{ $row->pinned ? Lang::get('reports::index.pin.pinned_aria') : Lang::get('reports::index.pin.pin_aria') }}"
                                    class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                                    style="{{ $row->pinned ? 'background: var(--color-text); color: var(--color-text-inverse); border-color: var(--color-text);' : '' }}"
                                ><span aria-hidden="true">📌</span> {{ $row->pinned ? Lang::get('reports::index.pin.pinned_label') : Lang::get('reports::index.pin.pin_label') }}</button>
                            </td>
                            @if ($confirmingDeleteId === $row->id)
                                <x-core::confirm-strip
                                    tag="td"
                                    style="padding: var(--space-2) var(--space-3);"
                                    :question="Lang::get('reports::index.delete_confirm', ['name' => $row->name])"
                                    :cancel-label="Lang::get('reports::index.cancel')"
                                    :confirm-label="Lang::get('reports::index.delete_report')"
                                    cancel="cancelDelete"
                                    :confirm="'deleteReport('.$row->id.')'"
                                />
                            @else
                                <td style="padding: var(--space-2) var(--space-3); text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: var(--space-2);">
                                        <a href="{{ Destination::Reports->url(['report' => $row->id]) }}" class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900" style="text-decoration: none;">{{ Lang::get('reports::index.open') }}</a>
                                        <a href="{{ Destination::Reports->url(['report' => $row->id]) }}" class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900" style="text-decoration: none;">{{ Lang::get('reports::index.edit') }}</a>

                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $row->id }})"
                                            aria-label="{{ Lang::get('reports::index.delete_aria', ['name' => $row->name]) }}"
                                            class="chip focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                                            style="color: var(--color-rose);"
                                        >{{ Lang::get('reports::index.delete') }}</button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
