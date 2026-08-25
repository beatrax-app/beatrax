@use('Modules\Core\Public\Support\Lang')
{{-- Laravel falls back to 4xx.blade.php for any 4xx without a view of its own.
     Without it a reader met the framework's page -- and under a dev build's
     APP_DEBUG that is a stack trace over this app's own source, with no
     navigation off it at all. 405 on /logout is how one was reached. --}}
<x-errors.beatrax-error
    :status="$exception?->getStatusCode() ?? 400"
    :title="Lang::get('core::errors.4xx.title')"
    :body="Lang::get('core::errors.4xx.body')"
/>
