<?php

declare(strict_types=1);

namespace Beatrax\Tooling\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

// Reaches only what phpstan.neon analyses, and every module's Database/Seeders
// is excluded there — so the demo seeders, where this shape was found, are not
// read by this rule. ASeededAmountRewrittenBehindItsFingerprintTest covers that.
/**
 * @implements Rule<MethodCall>
 */
final class FingerprintTupleRule implements Rule
{
    // The eight columns FingerprintComposer folds into the digest. A write that
    // moves any of them leaves the stored fingerprint describing a row that no
    // longer exists, and the next import of the same statement books a second
    // copy — which is the whole of what the fingerprint is for.
    private const array TUPLE = [
        'user_id',
        'account_id',
        'posted_at',
        'booked_at',
        'amount_minor',
        'currency',
        'counterparty_normalized',
        'occurrence_ordinal',
    ];

    private const string MODEL = 'Modules\Ledger\Models\Transaction';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param  MethodCall  $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $args = $node->getArgs();

        // Ordered cheapest first: targetsTransactions() asks the scope for a
        // type, which is the expensive half, and only an update() with an
        // argument can be a subject at all.
        if (! $node->name instanceof Node\Identifier
            || $node->name->toLowerString() !== 'update'
            || $args === []
            || ! $this->targetsTransactions($node->var, $scope)) {
            return [];
        }

        $errors = [];

        // Each constant-array variant is judged on its own: a union where one
        // branch writes the amount without the digest is still that branch.
        foreach ($scope->getType($args[0]->value)->getConstantArrays() as $written) {
            $columns = [];

            foreach ($written->getKeyTypes() as $key) {
                foreach ($key->getConstantStrings() as $name) {
                    $columns[] = $name->getValue();
                }
            }

            $moved = array_values(array_intersect(self::TUPLE, $columns));

            if ($moved === [] || in_array('fingerprint', $columns, true)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'This update moves %s on transactions without writing fingerprint. The digest is composed over that column, so the row would keep a fingerprint describing values it no longer holds — compose a new one in the same array.',
                implode(', ', $moved),
            ))->identifier('beatrax.fingerprintTuple')->build();
        }

        return $errors;
    }

    private function targetsTransactions(Node\Expr $expr, Scope $scope): bool
    {
        // The receiver may be a variable holding the builder, which the chain
        // walk cannot follow; larastan carries the model through the generic,
        // so the type answers where the syntax does not.
        if (str_contains($scope->getType($expr)->describe(VerbosityLevel::precise()), self::MODEL)) {
            return true;
        }

        while ($expr instanceof MethodCall) {
            if ($this->namesTheTable($expr)) {
                return true;
            }

            $expr = $expr->var;
        }

        return $expr instanceof StaticCall
            && $expr->class instanceof Node\Name
            && $scope->resolveName($expr->class) === self::MODEL;
    }

    private function namesTheTable(MethodCall $call): bool
    {
        if (! $call->name instanceof Node\Identifier || $call->name->toLowerString() !== 'table') {
            return false;
        }

        $args = $call->getArgs();

        return isset($args[0])
            && $args[0]->value instanceof String_
            && $args[0]->value->value === 'transactions';
    }
}
