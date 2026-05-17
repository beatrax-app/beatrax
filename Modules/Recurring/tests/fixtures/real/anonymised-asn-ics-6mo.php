<?php

declare(strict_types=1);

// Placeholder for the anonymised real ASN + ICS 6-month export. The
// actual anonymisation work (mining real exports, scrubbing PII,
// stabilising counterparty IBANs into deterministic tokens) is deferred
// to a phase-close-out task so Wave 0 stays unblocked. The stub keeps
// the contract test scaffold runnable today and the file lookup
// deterministic.

return [
    'transactions' => [],
    'expected' => [
        'series_count' => 0,
        'TODO_REAL_FIXTURE' => true,
    ],
];
