<?php

declare(strict_types=1);

// The chain link's signature hash is composed in two places: the resolver that
// mints it, and the enable-time sweep that rewrites it when the matching key it
// is built from changes. They cannot share a helper — a sweep in Ledger reaching
// into a Chains resolver is the wrong direction — so the expression is pinned
// instead. Drift is silent: the sweep would write a hash the resolver never
// reproduces, and the three-link auto-promotion counter would stay at zero.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
function chainSignatureExpression(string $relativePath): string
{
    $root = dirname((string) realpath(base_path('Modules')));
    $source = (string) file_get_contents($root.'/'.$relativePath);

    expect(preg_match('/function signatureHash\([^)]*\)[^{]*\{\s*(.+?)\s*\}/s', $source, $m))->toBe(1, $relativePath);

    /** @var array<int, string> $m */
    return preg_replace('/\s+/', ' ', $m[1]) ?? '';
}

it('composes the chain signature hash identically in the resolver and the sweep', function (): void {
    $resolver = chainSignatureExpression('Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php');
    $sweep = chainSignatureExpression('Modules/Ledger/Public/Services/CounterpartyKeyBackfill.php');

    expect($resolver)->toContain("hash('sha256'");
    expect($sweep)->toBe(str_replace('$normalisedMerchant', '$matchingKey', $resolver));
});
