@use('Modules\Core\Public\Support\Lang')
{{-- Laravel resolves errors/404.blade.php by status; the shell and the reasoning
     live in beatrax-error.blade.php. --}}
<x-errors.beatrax-error
    status="404"
    :title="Lang::get('core::errors.404.title')"
    :body="Lang::get('core::errors.404.body')"
/>
