{{--
    Per-file chip — one row in the ICS card connector step's multi-PDF
    queue. Renders the filename, a human-readable file size, and a
    state indicator (parsing / ready / failed). The chip also exposes
    an inline `×` remove button on hover so the user can drop an
    accidentally-included file without re-uploading the rest.

    Pure presentation: state lives on the parent step component and
    is passed in via props. The remove button dispatches a server-side
    `removeStatement` call so the parent owns the array mutation.

    Props:
      :filename   — original filename ("statement-2026-01.pdf").
      :sizeBytes  — raw byte count; rendered as KB or MB with
                     tabular-nums per UI-SPEC.
      :state      — one of "parsing" | "ready" | "failed"; drives the
                     prefix glyph and the chip color via the matching
                     `.per-file-chip.{state}` CSS variant.
      :index      — optional integer index into the parent's
                     `$statements` array; bound on the remove button's
                     `wire:click` so the parent can splice the right
                     entry out.
--}}
@use('Modules\Core\Public\Support\Fmt')
@use('Modules\Core\Public\Support\Lang')
@props([
    'filename',
    'sizeBytes' => 0,
    'state' => 'parsing',
    'index' => null,
])
@php
    /** @var string $filename */
    /** @var int $sizeBytes */
    /** @var string $state */

    $bytes = (int) $sizeBytes;
    $bytesPerUnit = 1024;
    $bytesPerMib = $bytesPerUnit * $bytesPerUnit;
    $sizeLabel = match (true) {
        $bytes >= $bytesPerMib => Fmt::number($bytes / $bytesPerMib, 1).' MB',
        $bytes >= $bytesPerUnit => Fmt::number($bytes / $bytesPerUnit).' KB',
        default => Fmt::number($bytes).' B',
    };

    $stateClass = match ($state) {
        'ready' => 'per-file-chip ready',
        'failed' => 'per-file-chip failed',
        default => 'per-file-chip parsing',
    };
@endphp

<span {{ $attributes->class([$stateClass]) }}>
    <span class="per-file-chip-name">{{ $filename }}</span>
    <span class="per-file-chip-size">· {{ $sizeLabel }}</span>
    @if ($index !== null)
        <button
            type="button"
            class="remove"
            aria-label="{{ Lang::get('onboarding::components.remove_file_aria', ['filename' => $filename]) }}"
            wire:click="removeStatement({{ (int) $index }})"
        >×</button>
    @endif
</span>
