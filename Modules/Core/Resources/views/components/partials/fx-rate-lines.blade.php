@use('Modules\Core\Public\Support\Lang')

{{--
    The rates behind one figure, a line per pair. Shared by the popover the
    screen gets and the inline block the PDF export gets, so the two cannot
    come to say different things.

    Included by `core::components.fx-disclosure` with $disclosure, $fxDated and
    $fxStaleNote already resolved.
--}}
<p class="fx-source">{{ Lang::get('core::fx.converted_to', ['currency' => $disclosure->currency]) }}</p>
@foreach ($disclosure->rates as $fxRate)
    <p class="fx-rate">{{ Lang::get('core::fx.rate_line', [
        'from' => $fxRate->from,
        'rate' => $fxRate->rateForDisplay(),
        'to' => $fxRate->to,
    ]) }}</p>
    <p class="fx-source">{{ $fxRate->sourceLabel() }} · {{ Lang::get('core::fx.as_of', ['date' => $fxDated($fxRate->asOf)]) }}</p>
@endforeach
@if ($fxStaleNote !== null)
    <p class="fx-stale-note">{{ $fxStaleNote }}</p>
@endif
