@use('Modules\Core\Public\Enums\Locale')
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

    $rows = $isGov
        ? [
            ['key' => 'help_url', 'label' => Lang::get('counterparties::profile.support.contact_help'), 'href' => $resource->helpUrl, 'primary' => true],
            ['key' => 'apply_url', 'label' => Lang::get('counterparties::profile.support.sign_in_apply'), 'href' => $resource->applyUrl],
            ['key' => 'rights_url', 'label' => Lang::get('counterparties::profile.support.your_rights'), 'href' => $resource->rightsUrl],
        ]
        : [
            ['key' => 'cancel_url', 'label' => Lang::get('counterparties::profile.support.cancel'), 'href' => $resource->cancelUrl, 'primary' => true],
            ['key' => 'support_url', 'label' => Lang::get('counterparties::profile.support.help_support'), 'href' => $resource->supportUrl],
            ['key' => 'cheaper_url', 'label' => Lang::get('counterparties::profile.support.cheaper_plan'), 'href' => $resource->cheaperUrl],
        ];

    // Every href here was judged at admission, so one that survived is an
    // absolute https address on a public host. The refused ones are named in
    // ->withheld and still get a chip: a route the corpus holds and this app
    // will not follow is something a reader can act on, a vanished chip is not.
    $links = array_values(array_filter($rows, static fn (array $row): bool => is_string($row['href'])));
    $withheld = array_values(array_filter($rows, static fn (array $row): bool => isset($resource->withheld[$row['key']])));

    // The notes are the provider's own prose, in the language the provider
    // conducts the cancellation in. Tagging the paragraph keeps a screen reader
    // from pronouncing Dutch with an English voice, and a reader who does not
    // have that language is told which one it is rather than left guessing.
    $notesLocale = Locale::tryFrom($resource->notesLang ?? '');
    $notesLanguage = $notesLocale !== null && $notesLocale->value !== Lang::locale() ? $notesLocale->label() : null;

    $mailto = $resource->mailtoHref();
    $phoneHref = $resource->phone !== null ? 'tel:'.preg_replace('/[^0-9+]/', '', $resource->phone) : null;

    $chipWithheld = 'display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--radius-md); border:1px dashed var(--color-border); font-size:var(--text-sm); font-weight:500; color:var(--color-text-muted); background:transparent;';
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
            <a class="support-chip" href="{{ $link['href'] }}" target="_blank" rel="noopener noreferrer"
                style="{{ ($link['primary'] ?? false) ? $chipPrimary : $chip }}">
                {{ $link['label'] }}
                <span aria-hidden="true" style="opacity:.6;">↗</span>
            </a>
        @endforeach

        @foreach ($withheld as $link)
            <span class="support-chip" style="{{ $chipWithheld }}">
                {{ $link['label'] }}
                <span style="opacity:.75;">· {{ Lang::get('counterparties::profile.support.withheld') }}</span>
            </span>
        @endforeach

        {{-- The envelope and the telephone below each end in an invisible
             U+FE0F. Without it the two phone engines disagree about whether the
             character is a picture or a glyph, and an editor shows nothing
             there to delete. --}}
        @if ($mailto !== null)
            <a class="support-chip" href="{{ $mailto }}" style="{{ $chip }}">
                {{ Lang::get('counterparties::profile.support.cancel_by_email') }} <span aria-hidden="true" style="opacity:.6;">✉️</span>
            </a>
        @endif

        @if ($phoneHref !== null)
            <a class="support-chip" href="{{ $phoneHref }}" style="{{ $chip }}">
                {{ $resource->phone }} <span aria-hidden="true" style="opacity:.6;">☎️</span>
            </a>
        @endif
    </div>

    @if ($resource->notes !== null)
        <p @if ($notesLocale !== null) lang="{{ $notesLocale->value }}" @endif
            style="margin:var(--space-3) 0 0; font-size:var(--text-xs); color:var(--color-text-muted); line-height:1.5;">
            {{ $resource->notes }}
        </p>
        @if ($notesLanguage !== null)
            <p style="margin:var(--space-1) 0 0; font-size:var(--text-xs); color:var(--color-text-faint); line-height:1.5;">
                {{ Lang::get('counterparties::profile.support.notes_language', ['language' => $notesLanguage]) }}
            </p>
        @endif
    @endif
</section>
