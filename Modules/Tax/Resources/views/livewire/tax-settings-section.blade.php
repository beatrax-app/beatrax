@use('Modules\Core\Public\Support\Lang')
{{--
    Tax Settings Section — Country + Category Management (D-07/D-08)
    UI-SPEC § 12: .settings-section primitive (280px meta-side + body grid)
    Livewire component: tax.settings-section
--}}

<div class="space-y-4">
{{-- ===== Tax country row ===== --}}
<div class="settings-section" data-testid="tax-country-row">
    <div class="settings-section__meta">
        <span class="settings-section__label">{{ Lang::get('tax::settings.country_label') }}</span>
        <p class="settings-section__desc">{{ Lang::get('tax::settings.country_desc') }}</p>
    </div>
    <div class="settings-section__body space-y-2">
        <select
            wire:change="setTaxCountry($event.target.value)"
            class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
            aria-label="{{ Lang::get('tax::settings.country_label') }}"
            data-testid="tax-country-select"
        >
            <option value="" @selected($taxCountryCode === '')>{{ Lang::get('tax::settings.country_choose') }}</option>
            @foreach (['nl', 'de', 'be', 'fr', 'gb', 'us'] as $code)
                <option value="{{ $code }}" @selected($taxCountryCode === $code)>{{ Lang::get('tax::settings.countries.'.$code) }}</option>
            @endforeach
        </select>
        @if ($taxCountryCode !== '')
            <p class="text-xs text-[var(--color-amber)]">
                {{ Lang::get('tax::settings.country_switch_warning') }}
            </p>
        @endif
    </div>
</div>

{{-- ===== Deduction categories row ===== --}}
<div class="settings-section mt-4" data-testid="tax-categories-row">
    <div class="settings-section__meta">
        <span class="settings-section__label">{{ Lang::get('tax::settings.categories_label') }}</span>
        <p class="settings-section__desc">{{ Lang::get('tax::settings.categories_desc') }}</p>
    </div>
    <div class="settings-section__body">
        @php
            /** @var list<\stdClass> $categories */
            $active   = array_filter((array) $categories, fn ($c) => ($c->status ?? '') === 'active');
            $archived = array_filter((array) $categories, fn ($c) => ($c->status ?? '') === 'archived');
        @endphp

        @if (empty($active) && $taxCountryCode === '')
            <p class="text-sm text-[var(--color-text-faint)]" data-testid="categories-empty">
                {{ Lang::get('tax::settings.categories_empty') }}
            </p>
        @else
            <ul class="max-h-80 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800" role="list">
                @foreach ($active as $cat)
                    <li
                        class="toggle-row group"
                        data-testid="category-row-{{ $cat->id }}"
                        x-data="{ editing: false, name: @js($cat->name) }"
                    >
                        {{-- Display row --}}
                        <template x-if="! editing">
                            <span class="flex-1 text-sm text-slate-900 dark:text-slate-100">
                                {{ $cat->name }}
                            </span>
                        </template>
                        {{-- Inline rename input (WR-11) --}}
                        <template x-if="editing">
                            <input
                                type="text"
                                x-model="name"
                                x-on:keydown.enter.prevent="$wire.renameCategory({{ $cat->id }}, name); editing = false"
                                x-on:keydown.escape.stop="editing = false; name = @js($cat->name)"
                                class="flex-1 rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 shadow-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                                aria-label="{{ Lang::get('tax::settings.rename_input_aria', ['name' => $cat->name]) }}"
                                data-testid="rename-input-{{ $cat->id }}"
                            />
                        </template>
                        @if ($cat->corpus_key !== null)
                            <span class="text-xs text-[var(--color-text-faint)] mr-2" aria-label="{{ Lang::get('tax::settings.from_corpus') }}">{{ Lang::get('tax::settings.from_corpus') }}</span>
                        @endif
                        <template x-if="! editing">
                            <button
                                type="button"
                                x-on:click="editing = true; $nextTick(() => $el.closest('li').querySelector('input')?.focus())"
                                class="pill-btn-ghost text-xs opacity-0 group-hover:opacity-100 focus:opacity-100 mr-1"
                                aria-label="{{ Lang::get('tax::settings.rename_aria', ['name' => $cat->name]) }}"
                                data-testid="rename-btn-{{ $cat->id }}"
                            >{{ Lang::get('tax::settings.rename') }}</button>
                        </template>
                        <template x-if="editing">
                            <button
                                type="button"
                                x-on:click="$wire.renameCategory({{ $cat->id }}, name); editing = false"
                                class="pill-btn-ghost text-xs mr-1"
                                aria-label="{{ Lang::get('tax::settings.rename_save_aria', ['name' => $cat->name]) }}"
                                data-testid="rename-save-{{ $cat->id }}"
                            >{{ Lang::get('tax::settings.save') }}</button>
                        </template>
                        <button
                            type="button"
                            wire:click="archiveCategory({{ $cat->id }})"
                            class="pill-btn-ghost text-xs opacity-0 group-hover:opacity-100 focus:opacity-100"
                            aria-label="{{ Lang::get('tax::settings.archive_aria', ['name' => $cat->name]) }}"
                        >{{ Lang::get('tax::settings.archive') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($renameError !== '')
            <p class="mt-1 text-xs text-[var(--color-rose)]" data-testid="rename-category-error">{{ $renameError }}</p>
        @endif

        {{-- Add category form --}}
        <div class="mt-3 flex gap-2" data-testid="add-category-form">
            <label for="new-category-name" class="sr-only">{{ Lang::get('tax::settings.new_category_label') }}</label>
            <input
                id="new-category-name"
                wire:model="newCategoryName"
                type="text"
                placeholder="{{ Lang::get('tax::settings.new_category_placeholder') }}"
                class="flex-1 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                data-testid="new-category-input"
            />
            <button
                type="button"
                wire:click="addCategory"
                class="pill-btn-ghost text-sm"
                data-testid="add-category-btn"
            >{{ Lang::get('tax::settings.add_category') }}</button>
        </div>

        @if ($addError !== '')
            <p class="mt-1 text-xs text-[var(--color-rose)]" data-testid="add-category-error">{{ $addError }}</p>
        @endif

        @if ($addSuccess)
            <p class="mt-1 text-xs text-[var(--color-emerald)]" data-testid="add-category-success"
               wire:init="$set('addSuccess', false)" wire:transition.duration.4000ms>
                {{ Lang::get('tax::settings.category_added') }}
            </p>
        @endif

        {{-- Archived disclosure --}}
        @if (!empty($archived))
            <details class="mt-4">
                <summary class="text-xs text-[var(--color-text-faint)] cursor-pointer">
                    {{ Lang::get('tax::settings.archived_count', ['count' => count($archived)]) }}
                </summary>
                <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-800" role="list">
                    @foreach ($archived as $cat)
                        <li class="toggle-row group py-1">
                            <span class="flex-1 text-sm text-[var(--color-text-muted)]">{{ $cat->name }}</span>
                            {{-- WR-11: archiving is reversible. --}}
                            <button
                                type="button"
                                wire:click="unarchiveCategory({{ $cat->id }})"
                                class="pill-btn-ghost text-xs opacity-0 group-hover:opacity-100 focus:opacity-100"
                                aria-label="{{ Lang::get('tax::settings.restore_aria', ['name' => $cat->name]) }}"
                                data-testid="unarchive-btn-{{ $cat->id }}"
                            >{{ Lang::get('tax::settings.restore') }}</button>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
</div>{{-- end categories row --}}
</div>{{-- end single root wrapper --}}
