<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\DevMode\Internal\Sql\SelectOnlyValidator;

it('rejects INSERT INTO statements', function (): void {
    expect(fn () => (new SelectOnlyValidator)->validate('INSERT INTO t VALUES (1)'))
        ->toThrow(ValidationException::class);
});

it('rejects UPDATE statements', function (): void {
    expect(fn () => (new SelectOnlyValidator)->validate('UPDATE t SET a=1'))
        ->toThrow(ValidationException::class);
});

it('rejects DELETE statements', function (): void {
    expect(fn () => (new SelectOnlyValidator)->validate('DELETE FROM t'))
        ->toThrow(ValidationException::class);
});

it('rejects DROP TABLE statements', function (): void {
    expect(fn () => (new SelectOnlyValidator)->validate('DROP TABLE t'))
        ->toThrow(ValidationException::class);
});

it('rejects WITH-CTE statements that write', function (): void {
    expect(fn () => (new SelectOnlyValidator)->validate('WITH x AS (SELECT 1) UPDATE t SET a=1'))
        ->toThrow(ValidationException::class);
});

it('rejects a SELECT followed by a semicolon and a write statement', function (): void {
    expect(fn () => (new SelectOnlyValidator)->validate('SELECT 1; INSERT INTO t VALUES (1)'))
        ->toThrow(ValidationException::class);
});

it('rejects a write hidden behind a leading block comment', function (): void {
    expect(fn () => (new SelectOnlyValidator)->validate('/* SELECT */ INSERT INTO t VALUES (1)'))
        ->toThrow(ValidationException::class);
});

it('rejects an empty statement', function (): void {
    expect(fn () => (new SelectOnlyValidator)->validate(''))
        ->toThrow(ValidationException::class);
});

it('rejects a whitespace-only statement', function (): void {
    expect(fn () => (new SelectOnlyValidator)->validate("   \n\n   "))
        ->toThrow(ValidationException::class);
});

it('accepts a plain SELECT', function (): void {
    (new SelectOnlyValidator)->validate('SELECT 1');
    expect(true)->toBeTrue(); // No exception thrown.
});

it('accepts SELECT * FROM t WHERE id = 1', function (): void {
    (new SelectOnlyValidator)->validate('SELECT * FROM users WHERE id = 1');
    expect(true)->toBeTrue();
});

it('accepts SELECT with leading line comment', function (): void {
    (new SelectOnlyValidator)->validate("-- comment\nSELECT 1");
    expect(true)->toBeTrue();
});

it('accepts SELECT with leading block comment', function (): void {
    (new SelectOnlyValidator)->validate('/* comment */ SELECT 1');
    expect(true)->toBeTrue();
});

it('rejects a CTE that reads (first-token rule is uniform — WITH is always rejected)', function (): void {
    // A read-only CTE is rejected on purpose: a first-token rule with no
    // carve-outs cannot be talked past, and the operator can rewrite the CTE
    // as a subquery.
    expect(fn () => (new SelectOnlyValidator)->validate('WITH x AS (SELECT 1) SELECT * FROM x'))
        ->toThrow(ValidationException::class);
});

it('exposes the rejection reason on the ValidationException for diagnostics', function (): void {
    try {
        (new SelectOnlyValidator)->validate('INSERT INTO t VALUES (1)');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        expect($errors)->toHaveKey('sql');
        expect($errors['sql'][0])->toStartWith('first_token_not_select:');

        return;
    }
    throw new RuntimeException('Expected ValidationException not thrown.');
});

it('reports semicolon_followed_by_statement when a stacked write follows the SELECT', function (): void {
    try {
        (new SelectOnlyValidator)->validate('SELECT 1; INSERT INTO t VALUES (1)');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        expect($errors)->toHaveKey('sql');
        expect($errors['sql'][0])->toBe('semicolon_followed_by_statement');

        return;
    }
    throw new RuntimeException('Expected ValidationException not thrown.');
});
