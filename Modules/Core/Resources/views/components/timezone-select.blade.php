@use('Modules\Core\Public\Services\InstallTimezone')
@use('Modules\Core\Public\Support\HostTimezone')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Internal\Support\TimezoneOptions')
@props(['selected', 'labelled' => false, 'selectClass' => '', 'fieldId' => 'timezone-select'])
{{--
    Every zone identifier the platform knows, grouped by region, plus the
    sentinel for "whatever machine this is". The sentinel names the detected
    zone in its own label, because "This machine" alone tells a reader nothing
    about which day they are about to read — and the whole point of the control
    is that the two answers can differ.

    `selected` is the STORED choice or the sentinel, never the resolved zone:
    showing the resolved one would make a reader who has chosen nothing look
    like they had chosen the zone they happen to be sitting in, and clearing it
    again would then be impossible.
--}}
@unless ($labelled)
    <label class="sr-only" for="{{ $fieldId }}">{{ Lang::get('core::settings.timezone.label') }}</label>
@endunless
<select
    id="{{ $fieldId }}"
    class="{{ $selectClass }}"
    {{ $attributes }}
>
    <option
        value="{{ InstallTimezone::THIS_MACHINE }}"
        @selected($selected === InstallTimezone::THIS_MACHINE)
    >{{ Lang::get('core::settings.timezone.this_machine', ['zone' => HostTimezone::detect()]) }}</option>
    @foreach (TimezoneOptions::grouped() as $region => $identifiers)
        <optgroup label="{{ $region }}">
            @foreach ($identifiers as $identifier)
                <option
                    value="{{ $identifier }}"
                    @selected($selected === $identifier)
                >{{ TimezoneOptions::label($identifier) }}</option>
            @endforeach
        </optgroup>
    @endforeach
</select>
