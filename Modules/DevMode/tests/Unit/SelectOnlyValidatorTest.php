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

it('accepts a CTE that only reads', function (): void {
    (new SelectOnlyValidator)->validate('WITH x AS (SELECT 1) SELECT * FROM x');
    expect(true)->toBeTrue();
});

it('rejects a CTE whose body writes', function (): void {
    expect(fn () => (new SelectOnlyValidator)->validate('WITH x AS (DELETE FROM t RETURNING *) SELECT * FROM x'))
        ->toThrow(ValidationException::class);
});

it('does not read a semicolon inside a string literal as a second statement', function (): void {
    // A bank description carries semicolons, and the operator was told their
    // WHERE clause was a stacked statement.
    (new SelectOnlyValidator)->validate("SELECT * FROM transactions WHERE description = 'a; b'");
    (new SelectOnlyValidator)->validate("SELECT ';' AS x");
    expect(true)->toBeTrue();
});

it('does not read a semicolon inside a trailing comment as a second statement', function (): void {
    (new SelectOnlyValidator)->validate('SELECT 1 -- note; still one statement');
    (new SelectOnlyValidator)->validate('SELECT 1 /* note; still one statement */');
    expect(true)->toBeTrue();
});

it('accepts a statement closed by a trailing semicolon', function (): void {
    (new SelectOnlyValidator)->validate('SELECT 1;');
    expect(true)->toBeTrue();
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
