@props([
    'topic',      // Required. Unique on the page — it becomes the panel's id.
    'label',      // Required. The visible label of the thing being explained, already localised.
    'body',       // Required. Two or three sentences, written for a reader, not for a maintainer.
])

@use('Modules\Core\Public\Support\Lang')

@php
    $helpTipPanelId = 'help-tip-'.$topic;
    $helpTipTitleId = $helpTipPanelId.'-title';
@endphp

{{--
    The help affordance beside a feature the reader may not recognise.

    Its mark is a glyph, not an emoji: an emoji here would say the mark IS the
    verb, and reading is not a verb. The mark is also plain ASCII inside a
    drawn circle rather than ⓘ or ℹ, because a picture-by-default code point
    without U+FE0F draws as line art on Android and as colour on iOS, and this
    one has to look the same on both.

    The panel is a native [popover] driven by popovertarget, so there is no
    JavaScript in the path at all and none of it depends on hover — which is
    the whole point, since `title` is inert on both shipped phones. Escape,
    light dismiss and focus return come from the platform; the Close button is
    there for the finger that does not know light dismiss exists.

    The label is passed in rather than restated, so the panel's heading is the
    same string as the control it explains and the two cannot drift apart.

    `.docs/conventions/help-a-reader-can-open.md` holds the rest: where the copy
    lives, the `@link` that ties each sentence to the page it was written from,
    and the surfaces deliberately left without a tip.
--}}
<button
    {{ $attributes->merge(['class' => 'help-tip']) }}
    type="button"
    popovertarget="{{ $helpTipPanelId }}"
    aria-label="{{ Lang::get('core::help.tip.about', ['subject' => $label]) }}"
>
    <span class="help-tip__mark" aria-hidden="true">?</span>
</button>

{{-- autofocus on the panel itself, not on a control inside it: a reader who
     opens help wants the help read out, and landing on Close announces the
     way out of a panel whose content was never spoken. --}}
<div
    popover
    id="{{ $helpTipPanelId }}"
    class="help-tip-panel"
    role="dialog"
    aria-labelledby="{{ $helpTipTitleId }}"
    tabindex="-1"
    autofocus
>
    <p id="{{ $helpTipTitleId }}" class="help-tip-panel__title">{{ $label }}</p>
    <p class="help-tip-panel__body">{{ $body }}</p>
    <x-core::neutral-button
        size="sm"
        class="help-tip-panel__close"
        popovertarget="{{ $helpTipPanelId }}"
        popovertargetaction="hide"
    >{{ Lang::get('core::help.tip.close') }}</x-core::neutral-button>
</div>
