@use('Modules\Core\Public\Support\Lang')
{{-- Laravel falls back to 4xx.blade.php for any 4xx the `errors::` namespace
     has no exact view for. Without it a reader met the framework's page -- and
     under a dev build's APP_DEBUG that is a stack trace over this app's own
     source, with no navigation off it at all. 405 on /logout is how one was
     reached.

     "No exact view" includes the framework's own: it ships 401, 402, 403 and
     429, and `errors::403` is found before `errors::4xx`, so this page never
     fired for any of them. Each now has a file of its own beside this one. --}}
<x-errors.beatrax-error
    :status="$exception?->getStatusCode() ?? 400"
    :title="Lang::get('core::errors.4xx.title')"
    :body="Lang::get('core::errors.4xx.body')"
/>
