{{--
    Drop zone — the dashed-border target the user drops their statement
    file onto on each connector step. Wraps the `input type="file"`
    so the entire dashed area is clickable; the parent step component's
    `wire:model` is supplied via the `:wireModel` prop so the connector
    step keeps ownership of the upload validation rule + extension set.

    Props:
      :wireModel  — Livewire model binding ("file") on the parent
                     connector step.
      :lead       — drop-zone primary copy ("Drop your statement file
                     here").
      :sublink    — secondary line ("or browse for a file").
      :glyph      — single emoji ("📥").
      :accept     — HTML accept attribute (".csv,.xml,.sta,.mt940,.940"
                     for ASN; ".pdf" for ICS).

    The drop-zone is purely visual — Livewire's `wire:model` on the
    nested `input` is the upload pipeline; no JavaScript drag
    handlers live here.
--}}
@props([
    'wireModel' => 'file',
    'lead' => 'Drop your statement file here',
    'sublink' => 'or browse for a file',
    'glyph' => '📥',
    'accept' => '',
])
@php
    /** @var string $wireModel */
    /** @var string $lead */
    /** @var string $sublink */
    /** @var string $glyph */
    /** @var string $accept */
@endphp

<label {{ $attributes->class(['drop-zone']) }}>
    <span class="drop-zone-glyph" aria-hidden="true">{{ $glyph }}</span>
    <span class="drop-zone-lead">{{ $lead }}</span>
    <span class="drop-zone-sublink">{{ $sublink }}</span>
    <input
        type="file"
        class="drop-zone-input"
        wire:model="{{ $wireModel }}"
        @if ($accept !== '') accept="{{ $accept }}" @endif
    />
</label>
