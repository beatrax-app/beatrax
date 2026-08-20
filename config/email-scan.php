<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OAuth loopback redirect port
    |--------------------------------------------------------------------------
    |
    | The wizard shows the user `http://127.0.0.1:{port}/oauth/callback/...`
    | to paste into Google Cloud Console / Azure Portal, and the runtime
    | exchange builds its URI from this same value — they must agree or the
    | provider's redirect-URI allow-list check rejects the exchange.
    |
    | Set it when the app is served somewhere that cannot be the callback
    | host: both providers reject `.test` redirect URIs, so a `.test` domain
    | needs a separate `php artisan serve --port=8000` for the callback.
    | Unset, the port is parsed from `app.url` if its host is loopback, and
    | otherwise defaults to 8000.
    |
    */
    'oauth_loopback_port' => env('OAUTH_LOOPBACK_PORT'),

    /*
    |--------------------------------------------------------------------------
    | ICS "statement ready" nudge detection
    |--------------------------------------------------------------------------
    |
    | `DetectIcsStatementReadyJob` matches these against the sender_email and
    | subject columns of `inbox_messages` ONLY — never the email body.
    |
    */
    'ics_statement_ready' => [
        // Matched on EXACT domain-part equality, never substring, so a
        // spoofed 'ics.nl.attacker.example' sender is rejected. Mirrors
        // IcsReceiptMatcher::ICS_DOMAINS, and IcsStatementSenderSeeder reads
        // this same list so the fetch filter and the detector cannot drift.
        'sender_domains' => ['ics.nl', 'icscards.nl'],

        // ICS Cards corresponds in both English and Dutch.
        'subject_pattern' => '/\b(statement|afschrift)\b/i',
    ],

];
