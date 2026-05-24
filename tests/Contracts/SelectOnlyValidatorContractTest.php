<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\DevMode\Internal\Sql\SelectOnlyValidator;

/*
 * Contract test — Locks the SelectOnlyValidator's `@internal` Tokenizer
 * mitigation surface (CONTEXT D-45 + RESEARCH Q2 + Pitfall 2).
 *
 * Doctrine\SqlFormatter\Tokenizer is marked `@internal`. The
 * SelectOnlyValidator class is the SINGLE seam in the codebase that
 * references it. This contract test pins the rejection cases so a
 * future doctrine/sql-formatter upgrade that reshapes the Tokenizer
 * fails LOUDLY here (and at PR review time) instead of silently
 * allowing a non-SELECT through.
 *
 * Maintenance contract: if you change SelectOnlyValidator.php you
 * MUST keep this test green (or update it together with the
 * implementation).
 */

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
