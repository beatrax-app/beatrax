@use('Modules\Core\Public\Support\Lang')
{{--
    Privacy banner — load-bearing personal-contact notice rendered at
    the top of a personal-type profile. The pink colour signals "this
    is personal, treated differently" before the user reads the copy;
    the banner is intentionally never re-styled to look generic.

    Copy is verbatim per the surface contract — substitution is not
    permitted.

    Aria:
      - `role="region"` + `aria-label="Privacy notice for personal contact"`
        gives screen reader users a landmark to skip to or away from.
--}}
<div
    {{ $attributes->merge(['class' => 'privacy-banner']) }}
    role="region"
    aria-label="{{ Lang::get('counterparties::components.privacy_banner.aria') }}"
>
    {{ Lang::get('counterparties::components.privacy_banner.body') }}
</div>
