<?php

declare(strict_types=1);

return [

    // Both the URI the wizard tells the user to paste and the one the runtime
    // exchange builds come from here, or the provider's allow-list rejects the
    // exchange. Neither provider accepts a `.test` redirect.
    'oauth_loopback_port' => env('OAUTH_LOOPBACK_PORT'),

    // Matched against the sender_email and subject columns of inbox_messages
    // only — never the email body.
    'ics_statement_ready' => [
        // Exact domain-part equality, never substring, so a spoofed
        // 'ics.nl.attacker.example' is rejected. IcsStatementSenderSeeder
        // reads this list too, so filter and detector cannot drift.
        'sender_domains' => ['ics.nl', 'icscards.nl'],

        // ICS Cards corresponds in both English and Dutch.
        'subject_pattern' => '/\b(statement|afschrift)\b/i',
    ],

];
