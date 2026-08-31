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
    private const array SKIP_TOKEN_TYPES = [
        Token::TOKEN_TYPE_WHITESPACE,
        Token::TOKEN_TYPE_COMMENT,
        Token::TOKEN_TYPE_BLOCK_COMMENT,
    ];

    private const string STATEMENT_SEPARATOR = ';';

    private const string SELECT = 'SELECT';

    private const string WITH = 'WITH';

    // Matched against a keyword token's FIRST word, because the tokenizer
    // emits `DELETE FROM` as a single token.
    /**
     * @var list<string>
     */
    private const array WRITE_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'UPSERT',
        'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME',
        'ATTACH', 'DETACH', 'PRAGMA', 'VACUUM', 'REINDEX', 'GRANT', 'REVOKE',
    ];

    /**
     * @throws ValidationException
     */
    public function validate(string $sql): void
    {
        $tokens = $this->significantTokens($sql);

        if ($tokens === []) {
            throw ValidationException::withMessages(['sql' => 'empty_statement']);
        }

        $first = $this->keyword($tokens[0]);

        if ($first === self::WITH) {
            $this->assertCteOnlyReads($tokens);
        } elseif ($first !== self::SELECT) {
            throw ValidationException::withMessages(['sql' => 'first_token_not_select:'.$first]);
        }

        $this->assertNoStackedStatement($tokens);
    }

    // Comments and string literals are dropped here rather than scanned for
    // with a regex: a `;` inside `WHERE description = 'a; b'` is data, and
    // telling the operator their bank description is a second statement names
    // a cause the tokenizer had already ruled out.
    /**
     * @return list<Token>
     *
     * @throws ValidationException
     */
    private function significantTokens(string $sql): array
    {
        try {
            $cursor = (new Tokenizer)->tokenize($sql);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['sql' => 'tokenizer_error:'.$e->getMessage()]);
        }

        $tokens = [];
        while (($token = $cursor->next()) !== null) {
            if (! in_array($token->type(), self::SKIP_TOKEN_TYPES, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    // A trailing `;` closes the one statement and is allowed; anything after
    // it is a second statement wearing the first one's first token.
    /**
     * @param  list<Token>  $tokens
     *
     * @throws ValidationException
     */
    private function assertNoStackedStatement(array $tokens): void
    {
        $last = count($tokens) - 1;
        foreach ($tokens as $index => $token) {
            if ($token->value() === self::STATEMENT_SEPARATOR && $index !== $last) {
                throw ValidationException::withMessages(['sql' => 'semicolon_followed_by_statement']);
            }
        }
    }

    /**
     * @param  list<Token>  $tokens
     *
     * @throws ValidationException
     */
    private function assertCteOnlyReads(array $tokens): void
    {
        $selects = 0;
        foreach ($tokens as $token) {
            $keyword = $this->keyword($token);
            $head = explode(' ', $keyword)[0];

            if (in_array($head, self::WRITE_KEYWORDS, true)) {
                throw ValidationException::withMessages(['sql' => 'cte_contains_write:'.$head]);
            }
            if ($keyword === self::SELECT) {
                $selects++;
            }
        }

        if ($selects === 0) {
            throw ValidationException::withMessages(['sql' => 'cte_without_select']);
        }
    }

    private function keyword(Token $token): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $token->value()) ?? $token->value();

        return strtoupper(trim($collapsed));
    }
}
