@use('Modules\Core\Public\Support\Lang')
@props(['options', 'selected' => '', 'placeholderDisabled' => false])
{{--
    The empty option and the country list, for the three surfaces that ask the
    reader which country they are in: signup, Settings and the onboarding step.

    The options rather than the whole control, because the <select> around them
    is not shared and should not be: signup's belongs to x-core::form-field,
    which owns the label, the hint and the aria wiring, and the three bind
    three different ways — a deferred wire:model, a wire:change action, a live
    wire:model. form-field says why a binding is never a prop, and the reason
    holds here: a spelling that only reproduces the bare directive would drop
    the modifier that decides WHEN the component updates.

    `placeholderDisabled` is not a style. It says whether THIS surface can go
    back to "no country at all": signup and the wizard can — not choosing is a
    real answer that widens classification to every region — so their empty
    option stays choosable. Settings cannot, because setCountry() refuses the
    empty value, and an option that would change nothing says so rather than
    accepting the gesture and discarding it.

    strlen, not a comparison against '': an empty-string literal inside a Blade
    directive reads to the HTML analyser as an opening attribute quote.
--}}
<option value="" @disabled($placeholderDisabled) @selected(strlen($selected) === 0)>{{ Lang::get('core::settings.country.choose') }}</option>
@foreach ($options as $code => $name)
    <option value="{{ $code }}" @selected($selected === $code)>{{ $name }}</option>
@endforeach
