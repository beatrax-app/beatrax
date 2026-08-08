@use('Modules\Core\Public\Enums\Locale')
@inject('translator', 'translator')
{{--
    Pre-auth language switch for the welcome / signup / login surfaces.

    Still a plain POST form: these screens are the first thing a fresh install
    renders, and the switch has to keep working if the JS bundle has not booted.
    Signed-in users keep using the richer Settings control, whose stored
    preference outranks this session key.

    The submit handler exists because a native form POST does not survive the
    mobile shell. NativePHP intercepts WebView requests and replays them into
    the embedded runtime, and its form path both drops the submitter (it builds
    `new FormData(form)`, which omits the button's own name/value — and `code`
    lives on the button) and loses the POST method, so Laravel answered 405
    with `Allow: POST` and redirected to `/locale`, which redirected again. The
    switcher looked dead and the app sat in a loop on a 405 page.

    fetch() IS intercepted reliably — it is the path every Livewire round-trip
    already takes — so submitting through it sidesteps both defects without
    changing the endpoint. Alpine absent, the native submit still runs: desktop
    keeps its no-JS guarantee, and the mobile WebView always has JS.
--}}
<form
    method="POST"
    action="{{ route('locale.switch') }}"
    class="locale-switcher"
    x-data
    x-on:submit.prevent="beatraxSubmitPostForm($el, $event.submitter)"
>
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
