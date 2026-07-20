<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

use Doctrine\SqlFormatter\Token;
use Doctrine\SqlFormatter\Tokenizer;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @link ../../../../.docs/features/dev-mode/architecture.md
 */
final class SelectOnlyValidator
{
    /**
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
