<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OAuth loopback redirect port
    |--------------------------------------------------------------------------
    |
    | The port the OAuth callback listener is bound to on the loopback IP.
    | The wizard surfaces `http://127.0.0.1:{port}/oauth/callback/{provider}`
    | for the user to paste into Google Cloud Console / Azure Portal; the
    | runtime exchange consumes the matching URI from the same source so
    | the provider's redirect-URI allow-list check passes.
    |
    | Set via `OAUTH_LOOPBACK_PORT` in .env when the app is served on a host
    | that cannot be the OAuth callback host (e.g. a `.test` domain on 443 —
    | both providers reject `.test` redirect
    | URIs). The user typically runs `php artisan serve --port=8000` in a
    | separate terminal for the callback handler and sets this to match.
    |
    | If unset, the URI computation falls back to parsing the port from
    | `app.url` when its host is `127.0.0.1` or `localhost`; otherwise
    | the project-wide default port 8000 is used.
    |
    */
    'oauth_loopback_port' => env('OAUTH_LOOPBACK_PORT'),

];
