@use('Modules\Core\Public\Support\Lang')
{{-- Laravel resolves errors/500.blade.php by status; the shell and the reasoning
     live in beatrax-error.blade.php. --}}
<x-errors.beatrax-error
    status="500"
    :title="Lang::get('core::errors.500.title')"
    :body="Lang::get('core::errors.500.body')"
/>
