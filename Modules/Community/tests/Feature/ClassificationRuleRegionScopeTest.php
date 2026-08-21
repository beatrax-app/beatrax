<?php

declare(strict_types=1);

use Modules\Community\Public\Services\ClassificationRuleProvider;

// Every region's file was loaded at once, so a Dutch health insurer was
// classified as a Belgian agency: ZORGPREMIE is a real Belgian rule and also the
// ordinary Dutch word for a health-insurance premium.

function governmentRegions(?string $region): array
{
    return array_values(array_unique(array_map(
        static fn (object $rule): string => $rule->region,
        app(ClassificationRuleProvider::class)->governmentRules($region),
    )));
}

it('returns only the named region when the reader has a country', function (): void {
    expect(governmentRegions('nl'))->toBe(['NL']);
});

it('accepts the country code in the case the user record stores it', function (): void {
    expect(governmentRegions('NL'))->toBe(governmentRegions('nl'));
});

// The Belgian rule that misfired. It still exists, and is still reachable for a
// reader in Belgium — it is simply no longer applied to everyone.
it('keeps another region reachable for a reader who lives there', function (): void {
    $belgian = app(ClassificationRuleProvider::class)->governmentRules('be');

    expect($belgian)->not->toBeEmpty();
    expect(array_filter($belgian, static fn (object $r): bool => $r->region !== 'BE'))->toBe([]);
});

// A reader who never named a country keeps what they had rather than losing
// classification entirely.
it('falls back to every region when the country is set to an empty string', function (): void {
    expect(count(governmentRegions('')))->toBeGreaterThan(1);
});

it('loads more than one region when nothing is asked for', function (): void {
    expect(count(governmentRegions(null)))->toBeGreaterThan(1);
});

it('scopes the bank-fee rules the same way', function (): void {
    $rules = app(ClassificationRuleProvider::class)->bankFeeRules('nl');

    expect($rules)->not->toBeEmpty();
    expect(array_filter($rules, static fn (object $r): bool => $r->region !== 'NL'))->toBe([]);
});
