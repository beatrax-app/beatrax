<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Closure;

/**
 * @link ../../../.docs/conventions/analyser-rules-enforced-locally.md#s3776--cognitive-complexity
 */
final class SonarCognitiveComplexity
{
    /** @var list<array{0:int|null,1:string,2:int}> */
    private array $tokens;

    /** @var array<int,int> */
    private array $brackets;

    private int $size;

    /**
     * How many tokens before each index can score anything at all. An
     * expression range whose two ends give the same number cannot contribute,
     * however deeply it nests, so the four passes below skip it outright.
     * Fluent query chains and array literals are most of this tree and none of
     * them score; reading them anyway cost five of the scan's six seconds.
     *
     * @var list<int>
     */
    private array $scoringPrefix;

    private int $value = 0;

    /**
     * How many function bodies enclose the cursor. Zero means the next one
     * entered is a function of the file in its own right, and is scored from
     * a clean slate; deeper than that, it only adds a level of nesting to the
     * function it sits in.
     */
    private int $functionDepth = 0;

    /** @var array<int,bool> */
    private array $scoredOperators = [];

    /** @var list<array{name:string,line:int,value:int}> */
    private array $functions = [];

    private function __construct(string $source)
    {
        $this->tokens = SonarSourceFiles::tokens($source);
        $this->brackets = SonarSourceFiles::brackets($this->tokens);
        $this->size = count($this->tokens);

        $running = 0;
        $prefix = [0];

        foreach ($this->tokens as $token) {
            if ($token[0] === null) {
                if ($token[1] === '?' || $token[1] === '|>') {
                    $running++;
                }
            } elseif (in_array($token[0], [T_BOOLEAN_AND, T_BOOLEAN_OR, T_FN, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $running++;
            }

            $prefix[] = $running;
        }

        $this->scoringPrefix = $prefix;
    }

    private function inert(int $from, int $to): bool
    {
        $start = max($from, 0);
        $end = min($to, $this->size);

        return $end <= $start || $this->scoringPrefix[$end] === $this->scoringPrefix[$start];
    }

    /**
     * The file's total, which is what the hosted analysis publishes as the
     * `cognitive_complexity` measure, and one entry per function the rule can
     * report on.
     *
     * @return array{total:int,functions:list<array{name:string,line:int,value:int}>}
     */
    public static function analyse(string $source): array
    {
        $reader = new self($source);
        $reader->statements(0, $reader->size, 0);

        return ['total' => $reader->value, 'functions' => $reader->functions];
    }

    private function id(int $index): ?int
    {
        return $index >= 0 && $index < $this->size ? $this->tokens[$index][0] : null;
    }

    private function text(int $index): string
    {
        return $index >= 0 && $index < $this->size ? $this->tokens[$index][1] : '';
    }

    private function line(int $index): int
    {
        return $index >= 0 && $index < $this->size ? $this->tokens[$index][2] : 0;
    }

    private function is(int $index, string $text): bool
    {
        return $index >= 0
            && $index < $this->size
            && $this->tokens[$index][0] === null
            && $this->tokens[$index][1] === $text;
    }

    private function close(int $index): int
    {
        return $this->brackets[$index] ?? $this->size;
    }

    /**
     * Every opener is a key in the bracket map pointing forward, so one hash
     * lookup answers this. Asking the token instead cost nine seconds across
     * the tree, because this is the check the whole walk turns on.
     */
    private function isBracket(int $index): bool
    {
        return ($this->brackets[$index] ?? -1) > $index;
    }

    /** The index of the `;` a statement ends on, or $to when it has none. */
    private function statementEnd(int $from, int $to): int
    {
        for ($i = $from; $i < $to; $i++) {
            if ($this->isBracket($i)) {
                $i = $this->close($i);

                continue;
            }
            if ($this->is($i, ';') || $this->id($i) === T_CLOSE_TAG) {
                return $i;
            }
        }

        return $to;
    }

    private function statements(int $from, int $to, int $level): void
    {
        $i = $from;

        while ($i < $to) {
            $next = $this->statement($i, $to, $level);
            $i = $next > $i ? $next : $i + 1;
        }
    }

    /** @return int the index just past the statement */
    private function statement(int $i, int $to, int $level): int
    {
        $id = $this->id($i);

        if ($this->is($i, '{')) {
            $end = $this->close($i);
            $this->statements($i + 1, min($end, $to), $level);

            return $end + 1;
        }
        if ($this->is($i, ';') || $this->is($i, '}')) {
            return $i + 1;
        }
        if ($id === T_ATTRIBUTE) {
            return $this->close($i) + 1;
        }

        // `final class C implements A, B` is a declaration, and reading it as
        // an expression splits it at that comma, which loses the class body
        // and everything scored inside it.
        if ($id === T_FINAL || $id === T_ABSTRACT || $id === T_READONLY) {
            return $this->statement($i + 1, $to, $level);
        }

        if (in_array($id, [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_INLINE_HTML], true)) {
            return $i + 1;
        }
        if (in_array($id, [T_NAMESPACE, T_USE, T_DECLARE, T_CONST], true)) {
            $end = $this->statementEnd($i, $to);

            return $this->is($end, ';') ? $end + 1 : $i + 1;
        }

        // A case label ends on `:`, not on `;`, so reading it as an expression
        // swallows the statements after it and everything they score.
        if ($id === T_CASE) {
            for ($j = $i + 1; $j < $to; $j++) {
                if ($this->isBracket($j)) {
                    $j = $this->close($j);

                    continue;
                }
                if ($this->is($j, ':') || $this->is($j, ';')) {
                    $this->exprRange($i + 1, $j, $level);

                    return $j + 1;
                }
            }

            return $to;
        }
        if ($id === T_DEFAULT) {
            return $this->is($i + 1, ':') || $this->is($i + 1, ';') ? $i + 2 : $i + 1;
        }

        if ($id === T_IF) {
            return $this->ifStatement($i, $to, $level, false);
        }
        if ($id === T_SWITCH) {
            $this->value += 1 + $level;

            return $this->headThenBody($i, $to, $level + 1, $level + 1, [T_ENDSWITCH]);
        }
        if ($id === T_WHILE) {
            $this->value += 1 + $level;

            return $this->headThenBody($i, $to, $level, $level + 1, [T_ENDWHILE]);
        }
        if ($id === T_FOR) {
            $this->value += 1 + $level;

            return $this->headThenBody($i, $to, $level, $level + 1, [T_ENDFOR]);
        }
        if ($id === T_FOREACH) {
            $this->value += 1 + $level;

            return $this->headThenBody($i, $to, $level, $level + 1, [T_ENDFOREACH]);
        }
        if ($id === T_DO) {
            return $this->doStatement($i, $to, $level);
        }
        if ($id === T_TRY) {
            return $this->tryStatement($i, $to, $level);
        }
        if ($id === T_BREAK || $id === T_CONTINUE) {
            $end = $this->statementEnd($i, $to);

            // Only a `break 2` costs anything: it is a jump the reader has to
            // count levels for, where a plain break is the structure already
            // on screen.
            if ($end > $i + 1) {
                $this->value++;
            }

            return $end + 1;
        }
        if ($id === T_GOTO) {
            $this->value++;

            return $this->statementEnd($i, $to) + 1;
        }
        if ($this->isTypeDeclaration($i)) {
            return $this->classLike($i, $to, $level);
        }
        if ($id === T_FUNCTION && $this->isNamed($i)) {
            return $this->functionLike($i, $to, $level);
        }

        $end = $this->statementEnd($i, $to);
        $this->exprRange($i, $end, $level);

        return $end + 1;
    }

    private function doStatement(int $i, int $to, int $level): int
    {
        $this->value += 1 + $level;
        $j = $this->body($i + 1, $to, $level + 1, []);

        if ($this->id($j) === T_WHILE) {
            $open = $j + 1;

            if ($this->is($open, '(')) {
                $end = $this->close($open);
                $this->exprRange($open + 1, $end, $level);
                $j = $end + 1;
            }
            if ($this->is($j, ';')) {
                $j++;
            }
        }

        return $j;
    }

    private function tryStatement(int $i, int $to, int $level): int
    {
        $j = $i + 1;

        if ($this->is($j, '{')) {
            $end = $this->close($j);
            $this->statements($j + 1, $end, $level);
            $j = $end + 1;
        }

        while ($this->id($j) === T_CATCH || $this->id($j) === T_FINALLY) {
            $isCatch = $this->id($j) === T_CATCH;

            if ($isCatch) {
                $this->value += 1 + $level;
                $open = $j + 1;
                $j = $this->is($open, '(') ? $this->close($open) + 1 : $open;
            } else {
                $j++;
            }

            if ($this->is($j, '{')) {
                $end = $this->close($j);
                $this->statements($j + 1, $end, $isCatch ? $level + 1 : $level);
                $j = $end + 1;
            }
        }

        return $j;
    }

    private function isTypeDeclaration(int $index): bool
    {
        $id = $this->id($index);

        if ($id === T_CLASS) {
            return $this->id($index - 1) !== T_DOUBLE_COLON;
        }

        return in_array($id, [T_INTERFACE, T_TRAIT, T_ENUM], true);
    }

    /**
     * PHP lets a method be named for a keyword, and the tokeniser hands back
     * the keyword's own token when it is: a method called `for` arrives as
     * T_FOR, not T_STRING. Anything that is not the opening parenthesis of a
     * closure is a name.
     */
    private function isNamed(int $index): bool
    {
        $next = $this->is($index + 1, '&') ? $index + 2 : $index + 1;

        return ! $this->is($next, '(');
    }

    /**
     * `keyword ( head ) body`, the head scored at one level and the body at
     * another.
     *
     * @param  list<int>  $endTokens
     */
    private function headThenBody(int $i, int $to, int $headLevel, int $bodyLevel, array $endTokens): int
    {
        $open = $i + 1;
        $j = $open;

        if ($this->is($open, '(')) {
            $end = $this->close($open);
            $this->exprRange($open + 1, $end, $headLevel);
            $j = $end + 1;
        }

        return $this->body($j, $to, $bodyLevel, $endTokens);
    }

    /**
     * A statement body: a braced block, an alternative-syntax run closed by
     * its own `end…` keyword, or a single unbraced statement.
     *
     * @param  list<int>  $endTokens
     */
    private function body(int $j, int $to, int $level, array $endTokens): int
    {
        if ($this->is($j, '{')) {
            $end = $this->close($j);
            $this->statements($j + 1, $end, $level);

            return $end + 1;
        }

        if ($this->is($j, ':') && $endTokens !== []) {
            $depth = 0;

            for ($k = $j + 1; $k < $to; $k++) {
                $id = $this->id($k);

                if (in_array($id, [T_IF, T_SWITCH, T_WHILE, T_FOR, T_FOREACH], true)) {
                    $depth++;
                } elseif (in_array($id, [T_ENDIF, T_ENDSWITCH, T_ENDWHILE, T_ENDFOR, T_ENDFOREACH], true)) {
                    if ($depth === 0) {
                        $this->statements($j + 1, $k, $level);

                        return $this->statementEnd($k, $to) + 1;
                    }
                    $depth--;
                }
            }

            $this->statements($j + 1, $to, $level);

            return $to;
        }

        return $this->statement($j, $to, $level);
    }

    private function ifStatement(int $i, int $to, int $level, bool $flat): int
    {
        $this->value += $flat ? 1 : 1 + $level;

        $open = $i + 1;
        $j = $open;

        if ($this->is($open, '(')) {
            $end = $this->close($open);
            $this->exprRange($open + 1, $end, $level);
            $j = $end + 1;
        }

        $alternative = $this->is($j, ':');
        $j = $this->body($j, $to, $level + 1, $alternative ? [T_ENDIF] : []);

        if ($alternative) {
            return $j;
        }

        while (true) {
            if ($this->id($j) === T_ELSEIF) {
                $this->value++;
                $j = $this->headThenBody($j, $to, $level, $level + 1, []);

                continue;
            }

            if ($this->id($j) === T_ELSE) {
                // `else if` is one else clause holding an if statement: the
                // else scores nothing, its nesting still counts, and the if it
                // holds scores flat rather than by depth. Written `elseif` the
                // arithmetic differs, which is why the two are not merged.
                if ($this->id($j + 1) === T_IF) {
                    return $this->ifStatement($j + 1, $to, $level + 1, true);
                }

                $this->value++;

                return $this->body($j + 1, $to, $level + 1, []);
            }

            return $j;
        }
    }

    private function classLike(int $i, int $to, int $level): int
    {
        $j = $i + 1;

        while ($j < $to && ! $this->is($j, '{') && ! $this->is($j, ';')) {
            $j++;
        }

        if (! $this->is($j, '{')) {
            return $j + 1;
        }

        $end = $this->close($j);
        $this->members($j + 1, $end, $level);

        return $end + 1;
    }

    private function members(int $from, int $to, int $level): void
    {
        for ($i = $from; $i < $to; $i++) {
            $id = $this->id($i);

            if ($id === T_ATTRIBUTE) {
                $i = $this->close($i);

                continue;
            }
            if ($id === T_FUNCTION && $this->isNamed($i)) {
                $i = $this->functionLike($i, $to, $level) - 1;

                continue;
            }
            if ($this->isTypeDeclaration($i)) {
                $i = $this->classLike($i, $to, $level) - 1;

                continue;
            }
            if ($this->is($i, '{')) {
                $i = $this->close($i);

                continue;
            }
            // A property or constant initialiser is an expression like any
            // other, and a closure parked in one scores where it sits.
            if ($this->is($i, '=')) {
                $end = $this->statementEnd($i, $to);
                $this->exprRange($i + 1, $end, $level);
                $i = $end;
            }
        }
    }

    private function functionLike(int $i, int $to, int $level): int
    {
        $nameIndex = $this->is($i + 1, '&') ? $i + 2 : $i + 1;
        $name = $this->text($nameIndex);
        $line = $this->line($i);

        $open = $nameIndex + 1;

        if (! $this->is($open, '(')) {
            return $i + 1;
        }

        $paramsEnd = $this->close($open);
        $j = $paramsEnd + 1;

        while ($j < $to && ! $this->is($j, '{') && ! $this->is($j, ';')) {
            $j++;
        }

        if (! $this->is($j, '{')) {
            return $j + 1;
        }

        $bodyEnd = $this->close($j);

        $this->enterFunction($level, $name, $line, true, function (int $inner) use ($open, $paramsEnd, $j, $bodyEnd): void {
            $this->params($open + 1, $paramsEnd, $inner);
            $this->statements($j + 1, $bodyEnd, $inner);
        });

        return $bodyEnd + 1;
    }

    /**
     * Runs a function body either on its own — nothing encloses it, so it is a
     * function of the file and the rule can report it — or folded into the
     * enclosing one at one more level of nesting.
     *
     * @param  Closure(int): void  $body
     */
    private function enterFunction(int $level, string $name, int $line, bool $reportable, Closure $body): void
    {
        if ($this->functionDepth === 0) {
            $enclosing = $this->value;
            $this->value = 0;
            $this->functionDepth = 1;
            $body(0);
            $this->functionDepth = 0;

            if ($reportable) {
                $this->functions[] = ['name' => $name, 'line' => $line, 'value' => $this->value];
            }

            $this->value += $enclosing;

            return;
        }

        $this->functionDepth++;
        $body($level + 1);
        $this->functionDepth--;
    }

    /**
     * A parameter list scores only through its default values. A type is not
     * an expression, and reading one as though it were turns every `?int` into
     * a ternary.
     */
    private function params(int $from, int $to, int $level): void
    {
        $start = $from;

        for ($i = $from; $i <= $to; $i++) {
            if ($i < $to && $this->isBracket($i)) {
                $i = $this->close($i);

                continue;
            }

            if ($i === $to || $this->is($i, ',')) {
                for ($k = $start; $k < $i; $k++) {
                    if ($this->isBracket($k)) {
                        $k = $this->close($k);

                        continue;
                    }
                    if ($this->is($k, '=')) {
                        $this->exprPart($k + 1, $i, $level);

                        break;
                    }
                }

                $start = $i + 1;
            }
        }
    }

    /** Splits a range on its top-level commas, then scores each part on its own. */
    private function exprRange(int $from, int $to, int $level): void
    {
        if ($this->inert($from, $to)) {
            return;
        }

        $start = $from;

        for ($i = $from; $i < $to; $i++) {
            if ($this->isBracket($i)) {
                $i = $this->close($i);

                continue;
            }
            if ($this->is($i, ',')) {
                $this->exprPart($start, $i, $level);
                $start = $i + 1;
            }
        }

        $this->exprPart($start, $to, $level);
    }

    private function exprPart(int $from, int $to, int $level): void
    {
        if ($this->inert($from, $to)) {
            return;
        }

        // A ternary and a function literal both run to the end of the
        // expression, so whichever opens first is the one enclosing the other.
        [$kind, $at] = $this->firstConstruct($from, $to);

        if ($kind === 'ternary') {
            $this->exprPart($from, $at, $level);
            $this->value += 1 + $level;
            $colon = $this->ternaryColon($at + 1, $to);
            $this->exprPart($at + 1, $colon, $level + 1);
            $this->exprPart($colon + 1, $to, $level + 1);

            return;
        }

        if ($kind === 'arrow') {
            $this->arrowFunction($from, $at, $to, $level);

            return;
        }

        if ($kind === 'closure') {
            $this->closure($from, $at, $to, $level);

            return;
        }

        $this->scoreLogical($from, $to);
        $this->walkExpr($from, $to, $level);
    }

    private function arrowFunction(int $from, int $at, int $to, int $level): void
    {
        $this->exprPart($from, $at, $level);

        $open = $this->is($at + 1, '&') ? $at + 2 : $at + 1;

        if (! $this->is($open, '(')) {
            return;
        }

        $paramsEnd = $this->close($open);
        $arrow = $paramsEnd + 1;

        while ($arrow < $to && $this->id($arrow) !== T_DOUBLE_ARROW) {
            $arrow++;
        }

        // An arrow function is a function for nesting and for the file's
        // total, and never a finding: the rule stops at the three shapes that
        // can hold statements.
        $this->enterFunction($level, '{closure}', $this->line($at), false, function (int $inner) use ($open, $paramsEnd, $arrow, $to): void {
            $this->params($open + 1, $paramsEnd, $inner);
            $this->exprPart($arrow + 1, $to, $inner);
        });
    }

    private function closure(int $from, int $at, int $to, int $level): void
    {
        $this->exprPart($from, $at, $level);

        $open = $this->is($at + 1, '&') ? $at + 2 : $at + 1;

        if (! $this->is($open, '(')) {
            return;
        }

        $paramsEnd = $this->close($open);

        // `use (...)` and a return type sit between the parameters and the
        // body; stepping over the group is what keeps its closing parenthesis
        // from reading as the end of the expression.
        $j = $paramsEnd + 1;

        while ($j < $to && ! $this->is($j, '{')) {
            if ($this->is($j, '(')) {
                $j = $this->close($j) + 1;

                continue;
            }
            $j++;
        }

        if (! $this->is($j, '{')) {
            return;
        }

        $bodyEnd = $this->close($j);

        $this->enterFunction($level, '{closure}', $this->line($at), true, function (int $inner) use ($open, $paramsEnd, $j, $bodyEnd): void {
            $this->params($open + 1, $paramsEnd, $inner);
            $this->statements($j + 1, $bodyEnd, $inner);
        });

        $this->exprPart($bodyEnd + 1, $to, $level);
    }

    /**
     * @return array{0:string,1:int} the construct that owns the rest of the
     *                               expression, and the index it opens at
     */
    private function firstConstruct(int $from, int $to): array
    {
        for ($i = $from; $i < $to; $i++) {
            if ($this->isBracket($i)) {
                $i = $this->close($i);

                continue;
            }

            // `: ?Type` is a return type. The only other `?` reachable here
            // opens a ternary, and a ternary can never follow a colon.
            if ($this->is($i, '?') && ! $this->is($i - 1, ':')) {
                return ['ternary', $i];
            }
            if ($this->id($i) === T_FN) {
                return ['arrow', $i];
            }
            if ($this->id($i) === T_FUNCTION) {
                return ['closure', $i];
            }
        }

        return ['none', $to];
    }

    private function ternaryColon(int $from, int $to): int
    {
        for ($i = $from; $i < $to; $i++) {
            if ($this->isBracket($i)) {
                $i = $this->close($i);

                continue;
            }
            if ($this->is($i, ':')) {
                return $i;
            }
        }

        return $to;
    }

    /**
     * One run of `&&`/`||` costs 1, and every switch between the two costs 1
     * more. Parentheses around an operand are transparent, so `($a && $b) ||
     * $c` is a single run of two operators; a call's arguments are not, and
     * score on their own.
     */
    private function scoreLogical(int $from, int $to): void
    {
        $operators = [];
        $this->collectLogical($from, $to, $operators);

        $previous = null;

        foreach ($operators as $index) {
            $this->scoredOperators[$index] = true;
            $text = $this->text($index);

            if ($previous === null || $text !== $previous) {
                $this->value++;
            }

            $previous = $text;
        }
    }

    /** @param  list<int>  $operators */
    private function collectLogical(int $from, int $to, array &$operators): void
    {
        for ($i = $from; $i < $to; $i++) {
            if ($this->is($i, '(')) {
                $end = $this->close($i);
                $before = $i - 1;
                $after = $end + 1;

                // Only a parenthesised operand of the run itself is
                // transparent. `!($a && $b) || $c` puts the `&&` under a
                // negation, which is a separate expression and scores again.
                if (($before < $from || $this->isLogical($before)) && ($after >= $to || $this->isLogical($after))) {
                    $this->collectLogical($i + 1, $end, $operators);
                }

                $i = $end;

                continue;
            }
            if ($this->isBracket($i)) {
                $i = $this->close($i);

                continue;
            }
            if ($this->isLogical($i) && ! isset($this->scoredOperators[$i])) {
                $operators[] = $i;
            }
        }
    }

    private function isLogical(int $index): bool
    {
        $id = $this->id($index);

        if ($id === T_BOOLEAN_AND || $id === T_BOOLEAN_OR) {
            return true;
        }

        return $id === null && $this->text($index) === '|>';
    }

    /** Descends into what the operator pass treated as opaque. */
    private function walkExpr(int $from, int $to, int $level): void
    {
        for ($i = $from; $i < $to; $i++) {
            if ($this->isTypeDeclaration($i)) {
                $i = $this->classLike($i, $to, $level) - 1;

                continue;
            }
            if ($this->isBracket($i)) {
                $end = $this->close($i);
                $this->exprRange($i + 1, $end, $level);
                $i = $end;
            }
        }
    }
}
