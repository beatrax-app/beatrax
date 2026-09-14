@use('Modules\Core\Public\Support\Lang')
{{-- The framework ships its own 401.blade.php, and Laravel looks for
     `errors::401` before `errors::4xx` — so the generic page beside this one
     never fired for this status and a reader met the framework's instead. Same
     copy as 4xx, through this app's shell. --}}
<x-errors.beatrax-error
    status="401"
    :title="Lang::get('core::errors.4xx.title')"
    :body="Lang::get('core::errors.4xx.body')"
/>
