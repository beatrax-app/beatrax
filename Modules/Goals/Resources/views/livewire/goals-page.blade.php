@use('Modules\Core\Public\Support\Lang')
@use('Modules\Goals\Internal\Enums\GoalProgressState')
{{--
    /goals page — list savings goals with 3-state progress bars and projected-
    date copy; Flux create/edit modal with inline field validation; Edit /
    kebab lifecycle (Mark complete, Archive); in-card archive micro-confirm +
    Restore toast; "Archived goals (N)" disclosure.

    Calm-slate direction: emerald in-progress/reached, amber overdue, rose
    archive-action. Tabular mono numerics throughout. The progress bar is
    x-core::progress-bar, so it is the shared 8px height rather than the 6px
    this page used to draw by hand. No width transitions
    (prefers-reduced-motion). Blade {{ }} escaping throughout.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)
        ->format();

    // target_currency is immutable and can diverge from the user's current base
    // currency — when editing, label the amount field with the goal's own
    // currency so the prefilled amount is not misread as a base-currency figure.
    $amountCurrency = $baseCurrency;
    if ($editGoalId !== 0) {
        foreach ($rows as $goalRow) {
            if ($goalRow->id === $editGoalId) {
                $amountCurrency = $goalRow->currency;
                break;
            }
        }
    }
@endphp

{{--
    Phone responsive pass (UI-SPEC §8/§9).

    At <768px:
    - Goals list renders as .card-list-item rows (name .primary, progress/target .secondary)
    - The create/edit flux:modal form is replaced by a bottom sheet that slides up from below
      (x-core::bottom-sheet). Trigger buttons dispatch open-sheet at phone width.
    At >=768px (desktop): card grid + Flux modal unchanged.
--}}
<div class="mx-auto max-w-3xl px-4 py-12">
    {{-- Page header --}}
    <header class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-core::page-heading>{{ Lang::get('goals::messages.page.title') }}</x-core::page-heading>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.page.subtitle') }}</p>
        </div>
        {{-- Trigger: phone → dispatch open-sheet; desktop → $flux.modal().show() --}}
        <x-core::neutral-button
            class="shrink-0"
            x-on:click="$wire.set('editGoalId', 0); if (window.innerWidth < 768) { $dispatch('open-sheet', { name: 'goal-form' }); } else { $flux.modal('goal-form').show(); }"
        >{{ Lang::get('goals::messages.page.add_goal') }}</x-core::neutral-button>
    </header>

    {{-- Active + completed goals list --}}
    @if (count($rows) === 0)
        <x-core::empty-state
            :heading="Lang::get('goals::messages.empty.heading')"
            :body="Lang::get('goals::messages.empty.body')"
        >
            <x-core::neutral-button x-on:click="$wire.set('editGoalId', 0); if (window.innerWidth < 768) { $dispatch('open-sheet', { name: 'goal-form' }); } else { $flux.modal('goal-form').show(); }">{{ Lang::get('goals::messages.empty.add_first') }}</x-core::neutral-button>
        </x-core::empty-state>
    @else
        {{-- Phone card list (hidden at >=768px via CSS .goals-phone-list display:none) --}}
        <style>
            @media (min-width: 768px) {
                .goals-phone-list { display: none !important; }
            }
            @media (max-width: 767px) {
                .goals-desktop-list { display: none !important; }
            }
        </style>

        {{-- Phone: .card-list-item rows --}}
        <ul class="goals-phone-list space-y-0 rounded-lg border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700 overflow-hidden">
            @foreach ($rows as $row)
                @php
                    $pct = $row->percentComplete();
                @endphp
                <li class="card-list-item">
                    <div class="flex-1 min-w-0">
                        <p class="primary truncate">{{ $row->name }}</p>
                        <p class="secondary">
                            {{ $fmt($row->contributedMinor, $row->currency) }} / {{ $fmt($row->targetMinor, $row->currency) }}
                            @if ($row->progressState === GoalProgressState::Overdue->value)
                                · <span class="text-amber-600 dark:text-amber-400">{{ Lang::get('goals::messages.status.overdue') }}</span>
                            @elseif ($row->progressState === GoalProgressState::Reached->value)
                                · <span class="text-emerald-700 dark:text-emerald-400">{{ Lang::get('goals::messages.status.reached') }}</span>
                            @elseif ($row->isCompleted())
                                {{-- Completed lived only on the desktop badge, so a
                                     finished goal read as an unfinished one at 375pt. --}}
                                · <span class="text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.status.completed') }}</span>
                            @endif
                        </p>

                        {{-- A bar and a date are one line each and fit at 375pt.
                             Dropping them left the phone with a bare percentage
                             and no sign of the target date the form insisted on. --}}
                        @unless ($row->isCompleted())
                            <div class="mt-2">
                                <x-core::progress-bar
                                    :value="$row->barWidth()"
                                    :tone="$row->progressState === GoalProgressState::Overdue->value ? 'warning' : 'positive'"
                                    :label="Lang::get('goals::messages.progress.aria', ['name' => $row->name, 'pct' => $pct])"
                                />
                            </div>
                        @endunless

                        @include('goals::partials.goal-target-date', ['row' => $row, 'class' => 'secondary mt-1 text-xs'])

                        @include('goals::partials.goal-projection-line', ['row' => $row, 'class' => 'secondary mt-1 text-xs'])
                    </div>
                    <span class="amount">{{ $pct }}%</span>
                    {{-- Row actions — always visible on phone. Drawn
                         as icons rather than the text characters they were:
                         a pencil, a tick and a box each carry their own
                         metrics, so none of them sat centred.

                         The three sit in their own shrink-0 group because the
                         row is flex-wrap: as three siblings they wrapped
                         individually, and on a 411px phone Edit stayed up on
                         the first line beside the percentage while Complete and
                         Archive dropped to the next one 300px to the left,
                         reading as one control belonging to the figure and two
                         belonging to nothing. --}}
                    <span class="flex shrink-0 items-center gap-1">
                    <x-core::emoji-action
                        :label="Lang::get('goals::messages.row.edit')"
                        x-on:click="
                            $wire.openEdit({{ $row->id }});
                            if (window.innerWidth < 768) {
                                $dispatch('open-sheet', { name: 'goal-form' });
                            } else {
                                $flux.modal('goal-form').show();
                            }
                        "
                    >✏️</x-core::emoji-action>
                    {{-- Complete and archive existed only in the desktop kebab,
                         which the phone list hides — so a goal could be created
                         and edited on a phone but never finished or put away. --}}
                    @if (! $row->isCompleted())
                        <x-core::emoji-action
                            :label="Lang::get('goals::messages.actions.mark_complete')"
                            wire:click="markComplete({{ $row->id }})"
                        >✅</x-core::emoji-action>
                    @endif
                    <x-core::emoji-action
                        :label="Lang::get('goals::messages.actions.archive')"
                        wire:click="confirmArchive({{ $row->id }})"
                    >🗄️</x-core::emoji-action>
                    </span>
                </li>

                @if ($archivingGoalId === $row->id)
                    {{-- The confirm strip lived only in the desktop card list,
                         which the phone hides — so the archive button set the
                         id and then nothing appeared. The control looked dead
                         because its answer was rendered somewhere unreachable. --}}
                    <x-core::confirm-strip
                        tag="li"
                        class="border-t border-slate-200 dark:border-slate-700"
                        :question="Lang::get('goals::messages.archive.confirm_question')"
                        :cancel-label="Lang::get('goals::messages.archive.close')"
                        :confirm-label="Lang::get('goals::messages.archive.archive')"
                        :confirm-aria="Lang::get('goals::messages.archive.confirm_aria', ['name' => $row->name])"
                        cancel="cancelArchive"
                        :confirm="'archive('.$row->id.')'"
                    />
                @endif
            @endforeach
        </ul>

        {{-- Desktop: existing card list (unchanged at >=768px) --}}
        <ul class="goals-desktop-list space-y-4">
            @foreach ($rows as $row)
                @php
                    $pct = $row->percentComplete();
                    $isReached = $row->progressState === GoalProgressState::Reached->value;
                    $isOverdue = $row->progressState === GoalProgressState::Overdue->value;
                    $isCompleted = $row->isCompleted();
                @endphp
                <li
                    class="rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700 {{ $isReached || $isCompleted ? 'border-l-[3px] border-l-emerald-500' : '' }}"
                >
                    {{-- Goal name row + status badge --}}
                    <div class="flex items-center justify-between gap-3">
                        <p class="min-w-0 truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $row->name }}</p>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($isReached)
                                <x-core::status-pill tone="positive">{{ Lang::get('goals::messages.status.reached') }}</x-core::status-pill>
                            @elseif ($isOverdue)
                                <x-core::status-pill tone="warning">{{ Lang::get('goals::messages.status.overdue') }}</x-core::status-pill>
                            @elseif ($isCompleted)
                                <x-core::status-pill>{{ Lang::get('goals::messages.status.completed') }}</x-core::status-pill>
                            @endif
                        </div>
                    </div>

                    {{-- Progress bar --}}
                    @if (! $isCompleted)
                        <div class="mt-3">
                            <x-core::progress-bar
                                :value="$row->barWidth()"
                                :tone="$row->progressState === GoalProgressState::Overdue->value ? 'warning' : 'positive'"
                                :label="Lang::get('goals::messages.progress.aria', ['name' => $row->name, 'pct' => $pct])"
                            />
                        </div>
                    @endif

                    {{-- Contributed / target + projected date --}}
                    <div class="mt-3 flex flex-wrap items-baseline justify-between gap-4">
                        <p class="text-sm" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                            {{ $fmt($row->contributedMinor, $row->currency) }}
                            <span class="text-slate-600 dark:text-slate-400" aria-hidden="true">/</span>
                            {{ $fmt($row->targetMinor, $row->currency) }}
                        </p>
                        @include('goals::partials.goal-projection-line', ['row' => $row, 'class' => 'shrink-0 text-xs text-slate-500 dark:text-slate-400'])
                    </div>

                    @include('goals::partials.goal-target-date', ['row' => $row, 'class' => 'mt-1 text-xs text-slate-500 dark:text-slate-400'])

                    {{-- Archive micro-confirm or footer actions --}}
                    @if ($archivingGoalId === $row->id)
                        <x-core::confirm-strip
                            class="mt-3"
                            :question="Lang::get('goals::messages.archive.confirm_question')"
                            :cancel-label="Lang::get('goals::messages.archive.close')"
                            :confirm-label="Lang::get('goals::messages.archive.archive')"
                            :confirm-aria="Lang::get('goals::messages.archive.confirm_aria', ['name' => $row->name])"
                            cancel="cancelArchive"
                            :confirm="'archive('.$row->id.')'"
                        />
                    @else
                        <div class="mt-3 flex items-center gap-2">
                            <x-core::emoji-action
                                :label="Lang::get('goals::messages.row.edit')"
                                wire:click="openEdit({{ $row->id }})"
                                x-on:click="
                                    if (window.innerWidth < 768) {
                                        $dispatch('open-sheet', { name: 'goal-form' });
                                    } else {
                                        $flux.modal('goal-form').show();
                                    }
                                "
                            >✏️</x-core::emoji-action>

                            <flux:dropdown>
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    class="emoji-action"
                                    icon="ellipsis-horizontal"
                                    aria-label="{{ Lang::get('goals::messages.actions.more_aria', ['name' => $row->name]) }}"
                                />
                                <flux:menu>
                                    @if (! $isCompleted)
                                        <flux:menu.item wire:click="markComplete({{ $row->id }})">{{ Lang::get('goals::messages.actions.mark_complete') }}</flux:menu.item>
                                    @endif
                                    <flux:menu.item wire:click="confirmArchive({{ $row->id }})">{{ Lang::get('goals::messages.actions.archive') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>{{-- end .goals-desktop-list --}}
    @endif

    {{-- Archived goals disclosure --}}
    @if (count($archived) > 0)
        <div class="mt-8">
            <button
                type="button"
                wire:click="$toggle('showArchived')"
                class="flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
            >
                <span>{{ Lang::get('goals::messages.archived_disclosure', ['count' => count($archived)]) }}</span>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4 transition-transform {{ $showArchived ? 'rotate-180' : '' }}" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            @if ($showArchived)
                <ul class="mt-4 space-y-4">
                    @foreach ($archived as $row)
                        <li class="rounded-lg border border-slate-200 bg-white p-4 opacity-60 dark:bg-slate-950 dark:border-slate-700">
                            <div class="flex items-center justify-between gap-3">
                                <p class="min-w-0 truncate text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $row->name }}</p>
                                <x-core::status-pill>{{ Lang::get('goals::messages.status.archived') }}</x-core::status-pill>
                            </div>
                            <div class="mt-3 flex flex-wrap items-baseline justify-between gap-4">
                                <p class="text-sm" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                                    {{ $fmt($row->contributedMinor, $row->currency) }}
                                    <span class="text-slate-600 dark:text-slate-400" aria-hidden="true">/</span>
                                    {{ $fmt($row->targetMinor, $row->currency) }}
                                </p>
                            </div>
                            <div class="mt-3">
                                <flux:dropdown>
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        class="emoji-action"
                                        icon="ellipsis-horizontal"
                                        aria-label="{{ Lang::get('goals::messages.actions.more_aria', ['name' => $row->name]) }}"
                                    />
                                    <flux:menu>
                                        <flux:menu.item wire:click="restore({{ $row->id }})">{{ Lang::get('goals::messages.actions.restore') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- ------------------------------------------------------------------- --}}
    {{-- Phone bottom sheet                                                  --}}
    {{-- At <768px: slides up as a sheet. At >=768px: hidden (flux modal     --}}
    {{-- handles desktop). Same Livewire wire: bindings as the flux modal.   --}}
    {{-- ------------------------------------------------------------------- --}}
    <x-core::bottom-sheet name="goal-form" :title="$editGoalId ? Lang::get('goals::messages.form.title_edit') : Lang::get('goals::messages.form.title_create')">
        <form
            wire:submit="{{ $editGoalId ? 'updateGoal' : 'createGoal' }}"
            class="space-y-4"
        >
            {{-- The inline 16px stays on every sheet control. size="base" is
                 text-base, and this theme redefines --text-base to 0.9375rem
                 (15px) — under Safari's 16px threshold, so the class alone
                 would let the viewport zoom on focus. --}}
            <div>
                <x-core::form-field
                    name="name"
                    field-id="goal-name-sheet"
                    :label="Lang::get('goals::messages.form.name')"
                    size="base"
                    wire:model="name"
                    :placeholder="Lang::get('goals::messages.form.name_placeholder')"
                    :aria-invalid="$errorName !== '' ? 'true' : null"
                    :aria-describedby="$errorName !== '' ? 'goal-name-sheet-error' : null"
                    style="font-size: 16px;"
                />
                @if ($errorName !== '')
                    <p id="goal-name-sheet-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorName }}</p>
                @endif
            </div>

            <div>
                <x-core::form-field
                    name="targetAmount"
                    field-id="goal-amount-sheet"
                    :label="Lang::get('goals::messages.form.target_amount', ['currency' => $amountCurrency])"
                    size="base"
                    inputmode="decimal"
                    wire:model="targetAmount"
                    :placeholder="Lang::get('core::components.amount_placeholder')"
                    :aria-invalid="$errorAmount !== '' ? 'true' : null"
                    :aria-describedby="$errorAmount !== '' ? 'goal-amount-sheet-error' : null"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                />
                @if ($errorAmount !== '')
                    <p id="goal-amount-sheet-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                @endif
            </div>

            <div>
                <label for="goal-date-sheet" class="mb-1 block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('goals::messages.form.target_date') }}</label>
                <x-core::date-input
                    field-id="goal-date-sheet"
                    wire:model.live="targetDate"
                    :aria-label="Lang::get('goals::messages.form.target_date')"
                    :aria-invalid="$errorDate !== '' ? 'true' : null"
                    :aria-describedby="$errorDate !== '' ? 'goal-date-sheet-error' : null"
                />
                @if ($errorDate !== '')
                    <p id="goal-date-sheet-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorDate }}</p>
                @endif
            </div>

            {{-- The desktop modal has carried this field since the feature
                 shipped and the sheet never did, so on a phone a goal could not
                 be linked to a pot at all -- the same shape as the missing
                 target-date field the modal comment below describes. --}}
            <div>
                <x-core::form-field
                    name="linkedPotId"
                    field-id="goal-pot-sheet"
                    type="select"
                    :label="Lang::get('goals::messages.form.linked_pot')"
                    :hint="Lang::get('goals::messages.form.linked_pot_help')"
                    wire:model="linkedPotId"
                    class="disabled:opacity-50"
                >
                    <option value="">{{ Lang::get('goals::messages.form.no_pot') }}</option>
                    @foreach ($pots as $pot)
                        <option value="{{ $pot->id }}">{{ $pot->name }}</option>
                    @endforeach
                </x-core::form-field>
                @if ($errorLinkedPot !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorLinkedPot }}</p>
                @endif
            </div>

            <div class="flex gap-3 pt-2">
                <x-core::neutral-button
                    block="flex"
                    type="submit"
                >{{ $editGoalId ? Lang::get('goals::messages.form.save_changes') : Lang::get('goals::messages.form.save_goal') }}</x-core::neutral-button>
                {{-- Closes on the client as well as clearing server state: the
                     panel's open flag is Alpine's, so wire:click alone left it
                     on screen however many times it was tapped. --}}
                <x-core::secondary-button
                    x-on:click="open = false"
                    wire:click="cancel"
                >{{ Lang::get('goals::messages.form.close') }}</x-core::secondary-button>
            </div>
        </form>
    </x-core::bottom-sheet>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Flux create / edit modal (desktop, >=768px)                          --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="goal-form" dismissible>
        <div class="pt-[44px]" style="max-width: 520px;">
            <x-core::section-heading :title="$editGoalId ? Lang::get('goals::messages.form.title_edit') : Lang::get('goals::messages.form.title_create')" />
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $editGoalId ? Lang::get('goals::messages.form.subtitle_edit') : Lang::get('goals::messages.form.subtitle_create') }}
            </p>

            <form
                wire:submit="{{ $editGoalId ? 'updateGoal' : 'createGoal' }}"
                class="mt-6 space-y-4"
            >
                {{-- Name --}}
                {{-- The invalid state is bound, not wrapped in @if: a directive
                     inside a component tag's attribute list defeats Blade's
                     tag regex, and the tag then reaches the page as an unknown
                     element that renders nothing. A null attribute is dropped
                     by the bag, which is the same output the @if gave. --}}
                <div>
                    <x-core::form-field
                        name="name"
                        field-id="goal-name"
                        :label="Lang::get('goals::messages.form.name')"
                        wire:model="name"
                        :placeholder="Lang::get('goals::messages.form.name_placeholder')"
                        :aria-invalid="$errorName !== '' ? 'true' : null"
                        :aria-describedby="$errorName !== '' ? 'goal-name-error' : null"
                    />
                    @if ($errorName !== '')
                        <p id="goal-name-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorName }}</p>
                    @endif
                </div>

                {{-- Target amount --}}
                <div>
                    <x-core::form-field
                        name="targetAmount"
                        field-id="goal-amount"
                        :label="Lang::get('goals::messages.form.target_amount', ['currency' => $amountCurrency])"
                        inputmode="decimal"
                        wire:model="targetAmount"
                        :placeholder="Lang::get('core::components.amount_placeholder')"
                        :aria-invalid="$errorAmount !== '' ? 'true' : null"
                        :aria-describedby="$errorAmount !== '' ? 'goal-amount-error' : null"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($errorAmount !== '')
                        <p id="goal-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                    @endif
                </div>

                {{-- Target date --}}
                <div>
                    <label for="goal-date" class="mb-1 block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('goals::messages.form.target_date') }}</label>
                    {{-- Two spellings of one field, and they cannot be collapsed
                         into one with an inline @if.

                         Blade's component-tag compiler matches the tag with a
                         regex over its attribute list, and a directive inside
                         that list defeats the match. It does not error — it
                         emits `<x-core::date-input …>` into the page as an
                         unknown HTML element, which renders as nothing. This
                         modal shipped with no target-date field at all, and the
                         only sign was a label with empty space under it. --}}
                    @if ($errorDate !== '')
                        <x-core::date-input
                            field-id="goal-date"
                            wire:model.live="targetDate"
                            :aria-label="Lang::get('goals::messages.form.target_date')"
                            aria-invalid="true"
                            aria-describedby="goal-date-error"
                        />
                    @else
                        <x-core::date-input
                            field-id="goal-date"
                            wire:model.live="targetDate"
                            :aria-label="Lang::get('goals::messages.form.target_date')"
                        />
                    @endif
                    @if ($errorDate !== '')
                        <p id="goal-date-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorDate }}</p>
                    @endif
                </div>

                {{-- Linked pot (optional); settable from the goal side --}}
                {{-- The help line becomes the component's own hint, which puts
                     it above the error rather than below it: $errorLinkedPot is
                     a plain string, not a bag entry, so it can only be a
                     sibling under the component. --}}
                <div>
                    <x-core::form-field
                        name="linkedPotId"
                        field-id="goal-pot"
                        type="select"
                        :label="Lang::get('goals::messages.form.linked_pot')"
                        :hint="Lang::get('goals::messages.form.linked_pot_help')"
                        wire:model="linkedPotId"
                        class="disabled:opacity-50"
                    >
                        <option value="">{{ Lang::get('goals::messages.form.no_pot') }}</option>
                        @foreach ($pots as $pot)
                            <option value="{{ $pot->id }}">{{ $pot->name }}</option>
                        @endforeach
                    </x-core::form-field>
                    @if ($errorLinkedPot !== '')
                        <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorLinkedPot }}</p>
                    @endif
                </div>

                {{-- Modal footer --}}
                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="cancel"
                        class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
                    >{{ Lang::get('goals::messages.form.close') }}</button>
                    <x-core::neutral-button type="submit">{{ $editGoalId ? Lang::get('goals::messages.form.save_changes') : Lang::get('goals::messages.form.save_goal') }}</x-core::neutral-button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
