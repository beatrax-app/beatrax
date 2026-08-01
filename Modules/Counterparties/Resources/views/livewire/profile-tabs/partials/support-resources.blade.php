@use('Modules\Core\Public\Support\Lang')
{{--
    Support-resource card (Overview tab) for merchant + government profiles.
    Renders only the links the bundled corpus actually has for this
    counterparty. External links open in a new tab; the phone number is a
    tel: link and the cancellation email (when a service supports it) a
    pre-filled mailto:.

    In scope:
      $resource  Modules\Community\Public\Dto\SupportResource
--}}
@php
    $isGov = $resource->type === \Modules\Counterparties\Public\Enums\CounterpartyType::Government->value;

    $links = $isGov
        ? [
            ['label' => Lang::get('counterparties::profile.support.contact_help'), 'href' => $resource->helpUrl, 'primary' => true],
            ['label' => Lang::get('counterparties::profile.support.sign_in_apply'), 'href' => $resource->applyUrl],
            ['label' => Lang::get('counterparties::profile.support.your_rights'), 'href' => $resource->rightsUrl],
        ]
        : [
            ['label' => Lang::get('counterparties::profile.support.cancel'), 'href' => $resource->cancelUrl, 'primary' => true],
            ['label' => Lang::get('counterparties::profile.support.help_support'), 'href' => $resource->supportUrl],
            ['label' => Lang::get('counterparties::profile.support.cheaper_plan'), 'href' => $resource->cheaperUrl],
        ];
    // Only emit http(s) link chips — never a javascript:/data: scheme, even
    // from the bundled corpus. mailto: and tel: are built separately below.
    $links = array_values(array_filter($links, static function ($l) {
        $href = $l['href'] ?? null;

        return is_string($href) && (str_starts_with($href, 'https://') || str_starts_with($href, 'http://'));
    }));

    $mailto = $resource->mailtoHref();
    $phoneHref = $resource->phone !== null ? 'tel:'.preg_replace('/[^0-9+]/', '', $resource->phone) : null;

    $chip = 'display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--radius-md); border:1px solid var(--color-border); font-size:var(--text-sm); font-weight:500; text-decoration:none; color:var(--color-text); background:var(--color-surface);';
    $chipPrimary = 'display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--radius-md); border:1px solid var(--color-text); font-size:var(--text-sm); font-weight:600; text-decoration:none; color:var(--color-text-inverse); background:var(--color-text);';
@endphp

<section
    aria-label="{{ $isGov ? Lang::get('counterparties::profile.support.aria_gov') : Lang::get('counterparties::profile.support.aria_merchant') }}"
    style="border:1px solid var(--color-border); border-radius:var(--radius-lg); padding:var(--space-5); margin-top:var(--space-6);"
>
    <h3 style="font-size:var(--text-xs); text-transform:uppercase; letter-spacing:0.04em; color:var(--color-text-faint); margin:0 0 var(--space-3);">
        {{ $isGov ? Lang::get('counterparties::profile.support.heading_gov') : Lang::get('counterparties::profile.support.heading_merchant') }}
    </h3>

    <div style="display:flex; flex-wrap:wrap; gap:var(--space-2);">
        @foreach ($links as $link)
            <a href="{{ $link['href'] }}" target="_blank" rel="noopener noreferrer"
                style="{{ ($link['primary'] ?? false) ? $chipPrimary : $chip }}">
                {{ $link['label'] }}
                <span aria-hidden="true" style="opacity:.6;">↗</span>
            </a>
        @endforeach

        @if ($mailto !== null)
            <a href="{{ $mailto }}" style="{{ $chip }}">
                {{ Lang::get('counterparties::profile.support.cancel_by_email') }} <span aria-hidden="true" style="opacity:.6;">✉</span>
            </a>
        @endif

        @if ($phoneHref !== null)
            <a href="{{ $phoneHref }}" style="{{ $chip }}">
                {{ $resource->phone }} <span aria-hidden="true" style="opacity:.6;">☎</span>
            </a>
        @endif
    </div>

    @if ($resource->notes !== null)
        <p style="margin:var(--space-3) 0 0; font-size:var(--text-xs); color:var(--color-text-muted); line-height:1.5;">
            {{ $resource->notes }}
        </p>
    @endif
</section>
