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
    aria-label="Privacy notice for personal contact"
>
    🔒 This is a personal contact. IBAN and personal details are hidden by default and never shared in exports.
</div>
