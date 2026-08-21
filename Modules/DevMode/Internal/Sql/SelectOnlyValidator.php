<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

use Doctrine\SqlFormatter\Token;
use Doctrine\SqlFormatter\Tokenizer;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        // Ahead of the tokenizer: `SELECT 1; INSERT …` presents a SELECT as
        // its first token, so the first-token check alone would pass it.
        if (preg_match('/;\s*\S/', $sql) === 1) {
            throw ValidationException::withMessages(['sql' => 'semicolon_followed_by_statement']);
        }

        try {
            $cursor = (new Tokenizer)->tokenize($sql);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['sql' => 'tokenizer_error:'.$e->getMessage()]);
        }

        // Cursor::next() skips one token type per call, hence the loop.
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
