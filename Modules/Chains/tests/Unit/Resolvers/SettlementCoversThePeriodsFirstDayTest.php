<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

// card_statements.period_start / period_end are DATETIME columns while
// transactions.posted_at is a DATE, and the import pipeline writes it bare.
// Compared as stored, a transaction posted on the period's FIRST day falls
// outside the range and never joins its settlement: the string
// '2026-04-17' sorts before '2026-04-17 00:00:00' because it is a prefix.
// Every fixture that wrote posted_at through the Eloquent model hid this,
// because the model used to serialise a time the field never carries.

it('reads a bare posted_at as outside a datetime period that starts on the same day', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $onFirstDay = $db->connection()->selectOne(
        "select ('2026-04-17' between '2026-04-17 00:00:00' and '2026-05-14 00:00:00') as covered",
    );

    expect((int) $onFirstDay->covered)->toBe(0);
});

it('covers that same day once the bounds are compared as days', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $onFirstDay = $db->connection()->selectOne(
        "select ('2026-04-17' between date('2026-04-17 00:00:00') and date('2026-05-14 00:00:00')) as covered",
    );
    $onLastDay = $db->connection()->selectOne(
        "select ('2026-05-14' between date('2026-04-17 00:00:00') and date('2026-05-14 00:00:00')) as covered",
    );
    $outside = $db->connection()->selectOne(
        "select ('2026-05-15' between date('2026-04-17 00:00:00') and date('2026-05-14 00:00:00')) as covered",
    );

    expect((int) $onFirstDay->covered)->toBe(1)
        ->and((int) $onLastDay->covered)->toBe(1)
        ->and((int) $outside->covered)->toBe(0);
});
