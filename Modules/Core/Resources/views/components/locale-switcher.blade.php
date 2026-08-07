@use('Modules\Core\Public\Enums\Locale')
@inject('translator', 'translator')
{{--
    Pre-auth language switch for the welcome / signup / login surfaces.

    A plain POST form rather than a Livewire action: these screens are the
    first thing a fresh install renders, and the switch has to work even if
    the JS bundle has not booted yet. Signed-in users keep using the richer
    Settings control, whose stored preference outranks this session key.
--}}
<form method="POST" action="{{ route('locale.switch') }}" class="locale-switcher">
    @csrf
    @foreach (Locale::cases() as $locale)
        @php($isActive = $translator->getLocale() === $locale->value)
        <button
            type="submit"
            name="code"
            value="{{ $locale->value }}"
            lang="{{ $locale->value }}"
            @if ($isActive) aria-current="true" @endif
            @class([
                'locale-switcher-option',
                'is-active' => $isActive,
            ])
        >
            {{ $locale->label() }}
        </button>
    @endforeach
</form>
