<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Modules\Core\Models\User;

/**
 * Wave 2 stub. The real deterministic + fuzzy PayPal funding-chain
 * resolver (CHN-01 + CHN-02 + D-106) ships in Wave 3 plan 05-04, which
 * replaces the empty body with the full algorithm.
 *
 * The class exists in Wave 2 so the `ResolveChainLinksJob::handle()`
 * signature can DI both resolvers identically across waves —
 * downstream waves can extend the stub without breaking Wave 2
 * tests that assert the job's resolver-invocation contract.
 *
 * Per CLAUDE.md DI-only: a stub class with an empty method is
 * preferable to a `?PaypalFundingResolver $paypal = null` argument
 * because it keeps the handle() shape stable and the singleton
 * binding in ChainsServiceProvider unconditional.
 */
final class PaypalFundingResolver
{
    public function resolveForUser(User $user): void
    {
        // Empty — Wave 3 (plan 05-04) ships the real PayPal funding
        // resolver. Silencing the unused-parameter lint by referencing
        // the argument keeps the strict-rules / unused-parameter
        // posture honest for the empty stub.
        unset($user);
    }
}
