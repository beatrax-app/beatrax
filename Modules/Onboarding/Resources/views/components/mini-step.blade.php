{{--
    Connector-step mini-tile — one of the four "Log in → Open afschriften
    → Pick a range → Download" guidance tiles above each connector's
    drop-zone. Sketch 003C-verbatim.

    Props:
      :glyph  — single emoji ("🔐" / "📑" / "📅" / "⬇️").
      :label  — short action label ("Log in", "Pick a range").
      :sub    — single-line sub-label ("mijn.icscards.nl", "Last 90 days").
      :subLang — BCP-47 tag when :sub is a verbatim foreign-language menu
                 name from the provider's own portal (PayPal NL's
                 "Aangepast → Betalingen"). Tagging it keeps a screen
                 reader from reading Dutch with an English voice, and
                 keeps the action label itself in the UI language.
      :state  — one of: 'done' | 'now' | 'upcoming'. Drives the tile bg
                 per UI-SPEC §"Accent reserved for" (emerald-bg for done,
                 surface-2 for now, default for upcoming).
--}}
@props([
    'glyph',
    'label',
    'sub' => '',
    'subLang' => '',
    'state' => 'upcoming',
])
@php
    $stateClass = match ($state) {
        'done' => 'mini done',
        'now' => 'mini now',
        default => 'mini',
    };
@endphp

<div {{ $attributes->class([$stateClass]) }}>
    <span class="mini-glyph" aria-hidden="true">{{ $glyph }}</span>
    <span class="mini-label">{{ $label }}</span>
    @if ($sub !== '')
        <span class="mini-sub" @if ($subLang !== '') lang="{{ $subLang }}" @endif>{{ $sub }}</span>
    @endif
</div>
