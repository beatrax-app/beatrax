{{--
    Self-account stub partial — rendered when the profile resolves to
    a `self_account` type. The shell view skips the hero + tab bar
    when this partial is in play; the stub is the entire body.

    Reuses the 17-06a `<x-counterparties::self-stub>` primitive; the
    `account` prop carries the display name so the verbatim
    "Open {name} account view →" CTA renders correctly.
--}}
@php
    $accountPayload = ['name' => $profile->displayName, 'slug' => $profile->slug];
@endphp

<x-counterparties::self-stub :account="$accountPayload" />
