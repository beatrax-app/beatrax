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

    /*
    |--------------------------------------------------------------------------
    | ICS "statement ready" nudge detection (Req 14, D-14/D-15)
    |--------------------------------------------------------------------------
    |
    | `DetectIcsStatementReadyJob` reads sender_email/subject columns from
    | `inbox_messages` ONLY (never the email body) and matches them against
    | this tunable pattern. No real ICS statement-ready email sample was
    | available at plan time (19-RESEARCH.md Open Question 1), so both
    | values below are a data-driven best guess — correct them here,
    | without a redeploy, once a real sample surfaces during UAT.
    |
    */
    'ics_statement_ready' => [
        // Sender domains the detector treats as ICS-authored. Matched via
        // EXACT domain-part equality (never substring) — mirrors
        // Modules\Receipts\Internal\Matchers\IcsReceiptMatcher::ICS_DOMAINS
        // so a spoofed 'ics.nl.attacker.example' sender is rejected
        // (T-19-16-02). `IcsStatementSenderSeeder` reads this SAME list so
        // the primary fetch filter (known_senders) and the detector never
        // drift out of sync.
        'sender_domains' => ['ics.nl', 'icscards.nl'],

        // Subject-line pattern (PCRE) the detector matches against. Covers
        // the English "statement" and Dutch "afschrift" wording ICS Cards
        // is known to use elsewhere in its correspondence.
        'subject_pattern' => '/\b(statement|afschrift)\b/i',
    ],

];
