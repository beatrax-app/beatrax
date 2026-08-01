{{--
    Shared front-end asset includes for every page shell: the Vite bundle
    (app CSS + JS), Livewire's styles, and Flux's appearance block. Kept in
    one component so the four layouts cannot drift on which assets they pull.
--}}
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
@fluxAppearance
