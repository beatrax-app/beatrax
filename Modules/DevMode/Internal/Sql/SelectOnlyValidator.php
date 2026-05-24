<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

use Doctrine\SqlFormatter\Token;
use Doctrine\SqlFormatter\Tokenizer;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Parse-time guard for the `/dev/sql` SELECT-only panel (CONTEXT D-45).
 *
 * **THIS CLASS IS THE SINGLE SEAM** for the doctrine/sql-formatter
 * library inside the codebase. The library's {@see Tokenizer} class is
 * marked `@internal` (verified at
 * github.com/doctrine/sql-formatter/blob/master/src/Tokenizer.php).
 * Per RESEARCH § Pattern 5 + Q2, the mitigation strategy is:
 *
 *   1. Wrap the tokenizer's instantiation + iteration here so only ONE
 *      file in the project references the @internal class.
 *   2. Lock the rejection cases in a contract test
 *      (`tests/Contracts/SelectOnlyValidatorContractTest.php`) so a
 *      future composer-update that reshapes the Tokenizer fails CI
 *      loudly instead of silently allowing a non-SELECT through.
 *
 * Maintenance rule: any change to this file MUST keep the contract
 * test green. If the library's API signature changes, update both
 * here and the contract test in the same commit so future readers
 * see the two-sided maintenance contract.
 *
 * The validator throws Laravel's {@see ValidationException} on every
 * rejection path. The exception's `errors()` array carries a single
 * `sql` key whose value documents the rejection reason — the SQL
 * panel UI surfaces this back to the operator as the
 * "Reject reason: {detail}" suffix per UI-SPEC § Copywriting.
 *
 * Rejection reasons:
 *   - `semicolon_followed_by_statement` — a SELECT followed by a
 *     semicolon + non-whitespace content (e.g. `SELECT 1; INSERT…`).
 *   - `empty_statement` — input was empty or whitespace / comment only.
 *   - `first_token_not_select:{token}` — the first non-skip token is
 *     not the SELECT keyword.
 */
final class SelectOnlyValidator
{
    /**
     * Token types skipped during the first-token lookup.
     *
     * @var list<int>
     */
    private const SKIP_TOKEN_TYPES = [
        Token::TOKEN_TYPE_WHITESPACE,
        Token::TOKEN_TYPE_COMMENT,
        Token::TOKEN_TYPE_BLOCK_COMMENT,
    ];

    /**
     * @throws ValidationException
     */
    public function validate(string $sql): void
    {
        // Reject semicolon-stacked statements BEFORE invoking the
        // tokenizer — a `SELECT 1; INSERT …` tokenizes as a SELECT
        // first-token + a boundary semicolon + an INSERT word; the
        // first-token check would let it through.
        if (preg_match('/;\s*\S/', $sql) === 1) {
            throw ValidationException::withMessages(['sql' => 'semicolon_followed_by_statement']);
        }

        try {
            $cursor = (new Tokenizer)->tokenize($sql);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['sql' => 'tokenizer_error:'.$e->getMessage()]);
        }

        // Walk past whitespace / comment tokens until we find the
        // first significant token. The Cursor::next() helper accepts
        // an `$exceptTokenType` int to skip — call it once per
        // skipped type until we land on a non-skip token.
        do {
            $token = $cursor->next();
            if ($token === null) {
                throw ValidationException::withMessages(['sql' => 'empty_statement']);
            }
            $type = $token->type();
        } while (in_array($type, self::SKIP_TOKEN_TYPES, true));

        $value = strtoupper(trim($token->value()));
        if ($value !== 'SELECT') {
            throw ValidationException::withMessages(['sql' => 'first_token_not_select:'.$value]);
        }
    }
}
