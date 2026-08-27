<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\FX\Public\Dto\ConversionResult;
use Modules\FX\Public\Enums\ConversionOutcome;
use Modules\Ledger\Public\ValueObjects\Money;

it('passthrough() reports the passthrough outcome with original equal to converted', function (): void {
    $money = Money::ofMinor(10000, 'EUR');

    $result = ConversionResult::passthrough($money);

    expect($result->outcome)->toBe(ConversionOutcome::Passthrough);
    expect($result->isPassthrough)->toBeTrue();
    expect($result->original->toMinor())->toBe(10000);
    expect($result->converted->toMinor())->toBe(10000);
    expect($result->original->currency())->toBe($result->converted->currency());
});

it('passthrough() yields null rate, source, asOf and isStale=false', function (): void {
    $money = Money::ofMinor(5000, 'EUR');

    $result = ConversionResult::passthrough($money);

    expect($result->rate)->toBeNull();
    expect($result->source)->toBeNull();
    expect($result->asOf)->toBeNull();
    expect($result->isStale)->toBeFalse();
});

it('noRate() reports a conversion that gave up, not one that was unnecessary', function (): void {
    $money = Money::ofMinor(10000, 'USD');

    $result = ConversionResult::noRate($money);

    expect($result->outcome)->toBe(ConversionOutcome::NoRate);
    expect($result->isPassthrough)->toBeFalse();
    expect($result->converted->currency())->toBe('USD');
    expect($result->rate)->toBeNull();
});

it('fully-constructed result exposes rate, source, asOf, and isStale for disclosure', function (): void {
    $original = Money::ofMinor(10000, 'USD');
    $converted = Money::ofMinor(9200, 'EUR');
    $asOf = CarbonImmutable::parse('2026-06-06');

    $result = new ConversionResult(
        original: $original,
        converted: $converted,
        outcome: ConversionOutcome::Converted,
        rate: '0.92000000',
        source: 'ecb',
        asOf: $asOf,
        isStale: false,
    );

    expect($result->rate)->toBe('0.92000000');
    expect($result->source)->toBe('ecb');
    expect($result->asOf)->toBeInstanceOf(CarbonImmutable::class);
    expect($result->asOf->toDateString())->toBe('2026-06-06');
    expect($result->isStale)->toBeFalse();
    expect($result->outcome)->toBe(ConversionOutcome::Converted);
    expect($result->isPassthrough)->toBeFalse();
});

it('fully-constructed stale result sets isStale=true', function (): void {
    $original = Money::ofMinor(10000, 'USD');
    $converted = Money::ofMinor(9200, 'EUR');
    $asOf = CarbonImmutable::parse('2026-01-01');

    $result = new ConversionResult(
        original: $original,
        converted: $converted,
        outcome: ConversionOutcome::Converted,
        rate: '0.92000000',
        source: 'bundled',
        asOf: $asOf,
        isStale: true,
    );

    expect($result->isStale)->toBeTrue();
    expect($result->source)->toBe('bundled');
});

it('rate property is typed ?string and never float', function (): void {
    $reflection = new ReflectionClass(ConversionResult::class);
    $rateParam = null;

    foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
        if ($param->getName() === 'rate') {
            $rateParam = $param;
            break;
        }
    }

    expect($rateParam)->not->toBeNull();

    $type = $rateParam?->getType();
    expect($type)->toBeInstanceOf(ReflectionNamedType::class);

    /** @var ReflectionNamedType $type */
    expect($type->getName())->toBe('string');
    expect($type->allowsNull())->toBeTrue();
});
