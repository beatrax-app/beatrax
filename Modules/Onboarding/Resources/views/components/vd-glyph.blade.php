{{--
    Welcome-step "value-driver" row primitive — a 44×44 emoji glyph tile
    paired with a title + description. Used by the welcome step's three
    "Here's what we'll set up" rows.

    Props:
      :glyph        — single emoji (already includes its own visual
                       weight — no decorative background tinting).
      :title        — bold one-liner ("Your bank" / etc.).
      :description  — single-line muted explainer.
      :optional     — when true, appends a faint "— optional" suffix to
                       the title (email row only).
--}}
@use('Modules\Core\Public\Support\Lang')
@props([
    'glyph',
    'title',
    'description',
    'optional' => false,
])

<li {{ $attributes->class(['vd-glyph']) }}>
    <span class="tile" aria-hidden="true">{{ $glyph }}</span>
    <div class="vd-glyph-text">
        <span class="vd-glyph-title">
            {{ $title }}
            @if ($optional)
                <span class="vd-glyph-optional">{{ Lang::get('onboarding::components.vd_optional') }}</span>
            @endif
        </span>
        <span class="vd-glyph-desc">{{ $description }}</span>
    </div>
</li>
