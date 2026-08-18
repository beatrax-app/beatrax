@use('Modules\Core\Public\Support\Lang')
{{-- Laravel resolves errors/503.blade.php by status; the shell and the reasoning
     live in beatrax-error.blade.php. --}}
<x-errors.beatrax-error
    status="503"
    :title="Lang::get('core::errors.503.title')"
    :body="Lang::get('core::errors.503.body')"
/>
