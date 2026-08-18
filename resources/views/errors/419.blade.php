@use('Modules\Core\Public\Support\Lang')
{{-- Laravel resolves errors/419.blade.php by status; the shell and the reasoning
     live in beatrax-error.blade.php. --}}
<x-errors.beatrax-error
    status="419"
    :title="Lang::get('core::errors.419.title')"
    :body="Lang::get('core::errors.419.body')"
/>
