@use('Modules\Core\Public\Support\Lang')
{{--
    Shared popover/sheet body.
    Referenced by both the desktop popover and the phone bottom-sheet inside
    tax-tag-popover.blade.php. All Livewire wire: bindings refer to the host
    component that uses HandlesTaxTagging.

    Both copies are in the document at once, one hidden by its breakpoint
    class, so $taxPickerSurface keeps their element ids apart. Without it the
    phone sheet's <label for> resolved to the hidden desktop textarea and the
    visible one had no label at all.
--}}
<div style="display: flex; flex-direction: column; gap: var(--space-3, 12px);">

    {{-- Note textarea --}}
    <div>
        <label
            for="tax-picker-note-{{ $taxPickerSurface }}-{{ $taxPickerTxId ?? 'none' }}"
            style="display: block; font-size: var(--text-xs, 12px); color: var(--color-text-muted, #64748b); margin-bottom: 4px;"
        >{{ Lang::get('tax::picker.note_label') }} <span style="color: var(--color-text-faint, #94a3b8);">{{ Lang::get('tax::picker.note_optional') }}</span></label>
        <textarea
            id="tax-picker-note-{{ $taxPickerSurface }}-{{ $taxPickerTxId ?? 'none' }}"
            wire:model="pickerNote"
            rows="1"
            placeholder="{{ Lang::get('tax::picker.note_placeholder') }}"
            style="
                width: 100%;
                font-size: 16px;
                border: 1px solid var(--color-border, #e2e8f0);
                border-radius: var(--radius-sm, 0.25rem);
                padding: 6px 8px;
                resize: vertical;
                background: var(--color-surface, #fff);
                color: var(--color-text, #0f172a);
                box-sizing: border-box;
            "
        ></textarea>
    </div>

    <hr style="border: 0; border-top: 1px solid var(--color-border, #e2e8f0); margin: 0;" />

    {{-- Category list --}}
    <div>
        <p style="font-size: var(--text-xs, 12px); color: var(--color-text-muted, #64748b); margin: 0 0 4px;">{{ Lang::get('tax::picker.category_label') }}</p>
        <div
            role="listbox"
            aria-label="{{ Lang::get('tax::picker.category_listbox_aria') }}"
            style="max-height: 200px; overflow-y: auto;"
        >
            {{-- "No category" first item (always present) --}}
            <button
                type="button"
                role="option"
                aria-selected="{{ $pickerCategoryId === null ? 'true' : 'false' }}"
                wire:click="$set('pickerCategoryId', null)"
                style="
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    width: 100%;
                    padding: 6px 8px;
                    font-size: var(--text-base, 15px);
                    color: var(--color-text-faint, #94a3b8);
                    background: {{ $pickerCategoryId === null ? 'var(--color-surface-2, #f1f5f9)' : 'transparent' }};
                    border: 0;
                    border-radius: var(--radius-sm, 0.25rem);
                    cursor: pointer;
                    text-align: left;
                    min-height: 32px;
                "
            >
                <span>{{ Lang::get('tax::picker.no_category') }}</span>
                @if ($pickerCategoryId === null)
                    <span style="color: var(--color-emerald, #10b981); font-size: var(--text-xs, 12px);">✓</span>
                @endif
            </button>

            {{-- User's categories --}}
            @foreach (($pickerCategories ?? []) as $cat)
                @php $catId = (int) $cat->id; @endphp
                <button
                    type="button"
                    role="option"
                    aria-selected="{{ $pickerCategoryId === $catId ? 'true' : 'false' }}"
                    wire:click="$set('pickerCategoryId', {{ $catId }})"
                    style="
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        width: 100%;
                        padding: 6px 8px;
                        font-size: var(--text-base, 15px);
                        color: var(--color-text, #0f172a);
                        background: {{ $pickerCategoryId === $catId ? 'var(--color-surface-2, #f1f5f9)' : 'transparent' }};
                        border: 0;
                        border-radius: var(--radius-sm, 0.25rem);
                        cursor: pointer;
                        text-align: left;
                        min-height: 32px;
                    "
                    data-testid="picker-cat-{{ $catId }}"
                >
                    <div>
                        <span>{{ $cat->name }}</span>
                        @if (isset($cat->hint) && $cat->hint !== null && $cat->hint !== '')
                            <span style="display: block; font-size: var(--text-xs, 12px); color: var(--color-text-faint, #94a3b8);">{{ $cat->hint }}</span>
                        @endif
                    </div>
                    @if ($pickerCategoryId === $catId)
                        <span style="color: var(--color-emerald, #10b981); font-size: var(--text-xs, 12px);">✓</span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>

    <hr style="border: 0; border-top: 1px solid var(--color-border, #e2e8f0); margin: 0;" />

    {{-- Inline new-category quick-add (server-rendered via @if — no Alpine state needed). --}}
    <div>
        @if ($pickerIsNewCatOpen ?? false)
            <div style="display: flex; gap: var(--space-2);">
                <input
                    type="text"
                    wire:model="pickerInlineNewName"
                    placeholder="{{ Lang::get('tax::picker.new_category_placeholder') }}"
                    style="
                        flex: 1 1 auto;
                        font-size: 16px;
                        border: 1px solid var(--color-border, #e2e8f0);
                        border-radius: var(--radius-sm, 0.25rem);
                        padding: 6px 8px;
                        background: var(--color-surface, #fff);
                        color: var(--color-text, #0f172a);
                        min-height: 44px;
                    "
                    aria-label="{{ Lang::get('tax::picker.new_category_aria') }}"
                />
                <button
                    type="button"
                    wire:click="addInlineCategory"
                    style="
                        background: var(--color-text, #0f172a);
                        color: #fff;
                        border: 0;
                        border-radius: var(--radius-sm, 0.25rem);
                        padding: 6px 12px;
                        font-size: var(--text-base, 15px);
                        cursor: pointer;
                        min-height: 44px;
                    "
                >{{ Lang::get('tax::picker.add') }}</button>
                <button
                    type="button"
                    wire:click="$set('pickerIsNewCatOpen', false)"
                    style="
                        background: transparent;
                        border: 0;
                        color: var(--color-text-muted, #64748b);
                        font-size: var(--text-xs, 12px);
                        cursor: pointer;
                        min-height: 44px;
                    "
                    aria-label="{{ Lang::get('tax::picker.cancel_new_category_aria') }}"
                >{{ Lang::get('tax::picker.cancel') }}</button>
            </div>
        @else
            <button
                type="button"
                wire:click="$set('pickerIsNewCatOpen', true)"
                style="
                    background: transparent;
                    border: 0;
                    color: var(--color-text-muted, #64748b);
                    font-size: var(--text-base, 15px);
                    cursor: pointer;
                    padding: 4px 0;
                    text-align: left;
                "
            >{{ Lang::get('tax::picker.new_category') }}</button>
        @endif
    </div>

    {{-- Year-override row (shown when booked year ≠ tax year) --}}
    @if (isset($pickerBookedYear) && isset($pickerTaxYear) && $pickerBookedYear !== null && $pickerTaxYear !== null && $pickerBookedYear !== $pickerTaxYear)
        <div>
            <p style="font-size: var(--text-xs, 12px); color: var(--color-text-muted, #64748b); margin: 0 0 4px;">
                {{ Lang::get('tax::picker.assign_year') }}
            </p>
            <div style="display: flex; gap: var(--space-2);">
                {{-- Booked-year button (override = null): never amber — amber marks the override. --}}
                <button
                    type="button"
                    wire:click="$set('pickerYearOverride', null)"
                    style="border: 1px solid var(--color-border, #e2e8f0); border-radius: 9999px; padding: 2px 10px; font-size: var(--text-xs, 12px); cursor: pointer; background: {{ $pickerYearOverride === null ? 'var(--color-emerald-bg, #d1fae5)' : 'transparent' }}; color: {{ $pickerYearOverride === null ? 'var(--color-emerald, #10b981)' : 'var(--color-text-muted, #64748b)' }};"
                >{{ $pickerBookedYear }}</button>
                <button
                    type="button"
                    wire:click="$set('pickerYearOverride', {{ $pickerTaxYear }})"
                    @class(['tax-badge--amber' => $pickerYearOverride !== null])
                    style="border: 1px solid var(--color-border, #e2e8f0); border-radius: 9999px; padding: 2px 10px; font-size: var(--text-xs, 12px); cursor: pointer; background: {{ $pickerYearOverride !== null ? 'var(--color-amber-bg, #fef3c7)' : 'transparent' }}; color: {{ $pickerYearOverride !== null ? 'var(--color-amber, #d97706)' : 'var(--color-text-muted, #64748b)' }};"
                >{{ $pickerTaxYear }}</button>
            </div>
        </div>
    @endif

    {{-- Footer: Save + Untag --}}
    <div style="display: flex; justify-content: space-between; align-items: center; gap: var(--space-2); padding-top: var(--space-2);">
        <button
            type="button"
            wire:click="saveTaxCategory"
            style="
                background: var(--color-text, #0f172a);
                color: #fff;
                border: 0;
                border-radius: var(--radius-sm, 0.25rem);
                padding: 8px 16px;
                font-size: var(--text-base, 15px);
                font-weight: 600;
                cursor: pointer;
                min-height: 44px;
            "
            data-testid="picker-save"
        >{{ Lang::get('tax::picker.save') }}</button>

        <button
            type="button"
            wire:click="untag"
            style="
                background: transparent;
                border: 0;
                color: var(--color-text-muted, #64748b);
                font-size: var(--text-xs, 12px);
                cursor: pointer;
                min-height: 44px;
            "
            data-testid="picker-untag"
        >{{ Lang::get('tax::picker.remove_tag') }}</button>
    </div>
</div>
