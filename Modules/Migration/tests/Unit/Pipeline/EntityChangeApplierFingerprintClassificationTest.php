<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Modules\Migration\Internal\Pipeline\EntityChangeApplier;

function migrationBuildQueryException(string $sqlState, string $driverMessage): QueryException
{
    $previous = new PDOException($driverMessage);
    // PDOException::$code holds a string SQLSTATE the constructor will not take,
    // so reflection is the only way to fabricate a realistic driver exception.
    $codeProperty = new ReflectionProperty(PDOException::class, 'code');
    $codeProperty->setValue($previous, $sqlState);

    return new QueryException('sqlite_testing', 'update "transactions" set "amount_minor" = ?, "fingerprint" = ? where "id" = ?', [], $previous);
}

function migrationInvokeIsFingerprintUniqueViolation(QueryException $e): bool
{
    $method = new ReflectionMethod(EntityChangeApplier::class, 'isFingerprintUniqueViolation');

    /** @var bool $result */
    $result = $method->invoke(null, $e);

    return $result;
}

it('classifies a genuine SQLite fingerprint-column unique violation as a collision', function (): void {
    $e = migrationBuildQueryException('23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: transactions.user_id, transactions.fingerprint');

    expect(migrationInvokeIsFingerprintUniqueViolation($e))->toBeTrue();
});

it('classifies a genuine SQLite composite (amount_minor) unique violation as a collision', function (): void {
    $e = migrationBuildQueryException('23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: transactions.user_id, transactions.account_id, transactions.posted_at, transactions.amount_minor, transactions.currency, transactions.counterparty_normalized, transactions.source_ref');

    expect(migrationInvokeIsFingerprintUniqueViolation($e))->toBeTrue();
});

it('does NOT classify a 23000 violation against an UNRELATED constraint as a collision (the caller must re-throw it)', function (): void {
    // The same SQLSTATE a NOT NULL / CHECK / other-unique violation reports,
    // but naming columns this UPDATE never touches: the unrelated failure that
    // used to be reclassified as a benign collision.
    $e = migrationBuildQueryException('23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: transactions.user_id, transactions.status');

    expect(migrationInvokeIsFingerprintUniqueViolation($e))->toBeFalse();
});

it('does NOT classify a non-23000 QueryException (e.g. a transient connection failure) as a collision', function (): void {
    $e = migrationBuildQueryException('HY000', 'SQLSTATE[HY000]: General error: 10 disk I/O error');

    expect(migrationInvokeIsFingerprintUniqueViolation($e))->toBeFalse();
});
