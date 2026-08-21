<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;

it('boots the Receipts module skeleton and resolves the MatcherRegistry', function (): void {
    $registry = $this->app->make(MatcherRegistry::class);

    expect($registry)->toBeInstanceOf(MatcherRegistry::class);

    // The receipts.matcher tag picks up whichever matcher classes are on disk,
    // so assert containment rather than equality and let the list grow.
    expect($registry->supportedKeys())->toContain('paypal-receipt');
});

it('exposes the SenderMatcher contract under Modules\\Receipts\\Public\\Contracts', function (): void {
    expect(interface_exists(SenderMatcher::class))->toBeTrue();

    $reflection = new ReflectionClass(SenderMatcher::class);
    $methodNames = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        $reflection->getMethods(),
    );

    expect($methodNames)->toContain('key', 'priority', 'canHandle', 'match');
});

it('builds a parsed MatchOutcomeDto from a ParsedReceiptDto', function (): void {
    $parsed = new ParsedReceiptDto(
        merchantName: 'Synthetic Merchant',
        amountMinor: -1299,
        currency: 'EUR',
        settledAmountMinor: null,
        settledCurrency: null,
        referenceId: 'O-00000000000000001',
        bookedAt: CarbonImmutable::parse('2026-05-17T12:00:00Z'),
        ownIban: 'PAYPAL',
        description: 'Synthetic Wave 0 boot test',
        rawPayload: [],
    );

    $outcome = MatchOutcomeDto::parsed($parsed);

    expect($outcome->kind)->toBe('parsed');
    expect($outcome->parsed)->toBe($parsed);
    expect($outcome->skipReason)->toBeNull();
});
