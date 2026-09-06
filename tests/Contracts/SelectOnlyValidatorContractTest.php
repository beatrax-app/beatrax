<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\DevMode\Internal\Sql\SelectOnlyValidator;

// SelectOnlyValidator is the only seam that touches
// Doctrine\SqlFormatter\Tokenizer, which is marked `@internal`. Pinning the
// rejection cases here makes an upgrade that reshapes it fail loudly instead of
// letting a non-SELECT through.
it('contract — rejects every non-SELECT first-token variant', function (string $sql): void {
    expect(fn () => (new SelectOnlyValidator)->validate($sql))
        ->toThrow(ValidationException::class);
})->with([
    'INSERT' => ['INSERT INTO t VALUES (1)'],
    'UPDATE' => ['UPDATE t SET a=1'],
    'DELETE' => ['DELETE FROM t'],
    'DROP' => ['DROP TABLE t'],
    'WITH-write' => ['WITH x AS (SELECT 1) UPDATE t SET a=1'],
    'semicolon-stack' => ['SELECT 1; INSERT INTO t VALUES (1)'],
    'comment-only-prefix' => ['/* SELECT */ INSERT INTO t VALUES (1)'],
]);

// The other half of the pin. A validator that rejected everything would satisfy
// the cases above without the tokenizer working at all, and the panel it guards
// would answer every query with a refusal nobody could act on.
it('contract — admits the reads the panel exists for', function (string $sql): void {
    expect(fn () => (new SelectOnlyValidator)->validate($sql))
        ->not->toThrow(ValidationException::class);
})->with([
    'plain' => ['SELECT 1'],
    'leading-whitespace' => ["\n  SELECT id FROM transactions LIMIT 1"],
    'lowercase' => ['select id from transactions'],
    'block-comment-prefix' => ['/* a note */ SELECT 1'],
    'WITH-read' => ['WITH x AS (SELECT 1) SELECT * FROM x'],
    'trailing-semicolon' => ['SELECT 1;'],
    'semicolon-in-a-literal' => ["SELECT id FROM transactions WHERE counterparty_name = 'a; b'"],
]);
