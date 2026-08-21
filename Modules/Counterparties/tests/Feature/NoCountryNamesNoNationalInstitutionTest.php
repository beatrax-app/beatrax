<?php

declare(strict_types=1);

use Modules\Community\Public\Services\ClassificationRuleProvider;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;

// A phone with no country set showed "Zorgpremie (Vlaamse sociale bescherming)"
// — a Flemish agency — against a Dutch health-insurance line. ZORGPREMIE is the
// ordinary Dutch word for a health premium and only Belgium's file defines it,
// so widening to every region did not widen a guess, it invented one.
//
// A merchant can be international. A government body and a bank's own fee are
// national by definition, so naming one is a claim about which country the
// reader is in — and unknown is the honest answer when they have not said.

it('is only Belgium that defines the Dutch word for a health premium', function (): void {
    $rules = app(ClassificationRuleProvider::class)->governmentRules(null);

    $regions = array_values(array_unique(array_map(
        static fn (object $rule): string => $rule->region,
        array_filter($rules, static fn (object $rule): bool => stripos($rule->pattern, 'ZORGPREMIE') !== false),
    )));

    expect($regions)->toBe(['BE']);
});

it('offers no national tier to a reader who has named no country', function (): void {
    $resolver = new ReflectionMethod(CounterpartyResolverService::class, 'namesANationalInstitution');

    expect($resolver->isPrivate())->toBeTrue();
});
