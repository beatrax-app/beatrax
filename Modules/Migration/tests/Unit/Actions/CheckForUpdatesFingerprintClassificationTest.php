<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Modules\Migration\Public\Actions\CheckForUpdates;

/**
 * WR-03 (13.5 deep review): `CheckForUpdates::applyTransactionAmount()`
 * previously treated ANY `QueryException` as a benign fingerprint
 * collision, masking unrelated DB failures behind the same "it's just a
 * collision, left unchanged" user-facing message. These tests exercise the
 * classification helper (`isFingerprintUniqueViolation()`) directly via
 * reflection — it is the exact boundary the fix narrows, and constructing
 * synthetic `QueryException`s lets every branch be pinned deterministically
 * without needing to engineer a real DB failure of each specific kind.
 */
function migrationBuildQueryException(string $sqlState, string $driverMessage): QueryException
{
    $previous = new PDOException($driverMessage);
    // PDOException::$code is the one Exception subclass PHP lets a real PDO
    // driver populate with a string SQLSTATE (e.g. '23000') rather than an
    // int — the constructor only accepts an int, so reflection is needed to
    // fabricate a realistic driver exception for this test.
    $codeProperty = new ReflectionProperty(PDOException::class, 'code');
    $codeProperty->setValue($previous, $sqlState);

    return new QueryException('sqlite_testing', 'update "transactions" set "amount_minor" = ?, "fingerprint" = ? where "id" = ?', [], $previous);
}

function migrationInvokeIsFingerprintUniqueViolation(QueryException $e): bool
{
    $method = new ReflectionMethod(CheckForUpdates::class, 'isFingerprintUniqueViolation');

    /** @var bool $result */
    $result = $method->invoke(null, $e);

    return $result;
}

it('WR-03: a genuine SQLite fingerprint-column unique violation IS classified as a collision', function (): void {
    $e = migrationBuildQueryException('23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: transactions.user_id, transactions.fingerprint');

    expect(migrationInvokeIsFingerprintUniqueViolation($e))->toBeTrue();
});

it('WR-03: a genuine SQLite composite (amount_minor) unique violation IS classified as a collision', function (): void {
    $e = migrationBuildQueryException('23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: transactions.user_id, transactions.account_id, transactions.posted_at, transactions.amount_minor, transactions.currency, transactions.counterparty_normalized, transactions.source_ref');

    expect(migrationInvokeIsFingerprintUniqueViolation($e))->toBeTrue();
});

it('WR-03: a 23000 violation against an UNRELATED constraint is NOT classified as a collision (must be re-thrown by the caller)', function (): void {
    // Same SQLSTATE (23000) a NOT NULL/CHECK/other-unique violation would
    // also report, but naming columns this UPDATE never touches — this is
    // exactly the "unrelated failure silently reclassified as a benign
    // collision" bug WR-03 describes.
    $e = migrationBuildQueryException('23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: transactions.user_id, transactions.status');

    expect(migrationInvokeIsFingerprintUniqueViolation($e))->toBeFalse();
});

it('WR-03: a non-23000 QueryException (e.g. a transient connection failure) is NOT classified as a collision', function (): void {
    $e = migrationBuildQueryException('HY000', 'SQLSTATE[HY000]: General error: 10 disk I/O error');

    expect(migrationInvokeIsFingerprintUniqueViolation($e))->toBeFalse();
});
