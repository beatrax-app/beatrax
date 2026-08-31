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
                     for a bank statement; ".pdf" for a card statement).
      :fileLabel  — the format's own name ("CSV", "PDF"), untranslated
                     like the format chips. Keeps the touch copy specific.
      :multiple   — allow several files at once (the card statements step).

    The drop-zone is purely visual — Livewire's `wire:model` on the
    nested `input` is the upload pipeline; no JavaScript drag
    handlers live here.

    Which is why the phone runtime gets different copy. The whole label is
    the file picker, so tapping it has always worked — but "Drop your CSV
    here" names a gesture the device does not have, and the only line that
    described a reachable action was the smaller one underneath. The caller's
    format is not thrown away with the gesture: the touch lead names it too,
    so the reader still knows which file the zone wants.
--}}
@use('Modules\Core\Public\Services\UserDataPathService')
@use('Modules\Core\Public\Support\Lang')
@props([
    'wireModel' => 'file',
    'lead' => null,
    'sublink' => null,
    'glyph' => '📥',
    'accept' => '',
    'fileLabel' => null,
    'multiple' => false,
])
@php
    /** @var string $wireModel */
    /** @var ?string $lead */
    /** @var ?string $sublink */
    /** @var string $glyph */
    /** @var string $accept */
    /** @var ?string $fileLabel */
    /** @var bool $multiple */

    $lead ??= Lang::get('onboarding::components.drop_zone_lead');
    $sublink ??= Lang::get('onboarding::components.drop_zone_sublink');

    if (UserDataPathService::isMobileRuntime()) {
        $lead = $fileLabel === null
            ? Lang::get('onboarding::components.drop_zone_touch_lead')
            : Lang::get('onboarding::components.drop_zone_touch_lead_named', ['file' => $fileLabel]);
        $sublink = null;
    }
@endphp

<label {{ $attributes->class(['drop-zone']) }}>
    <span class="drop-zone-glyph" aria-hidden="true">{{ $glyph }}</span>
    <span class="drop-zone-lead">{{ $lead }}</span>
    @if ($sublink !== null)
        <span class="drop-zone-sublink">{{ $sublink }}</span>
    @endif
    <input
        type="file"
        class="drop-zone-input"
        wire:model="{{ $wireModel }}"
        @if ($accept !== '') accept="{{ $accept }}" @endif
        @if ($multiple) multiple @endif
    />
</label>
