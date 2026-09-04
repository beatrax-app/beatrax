<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->adapter = $this->app->make(Mt940Adapter::class);
});

// "1300,000" is three fractional digits on a two-digit currency: shaped like a
// balance cell, so the tag is unmistakably present, with an amount behind it
// that cannot be read at this currency's scale.
function mt940PagedStatementClosing(string $finalBalance, string $intermediate = 'C260415EUR1100,00'): string
{
    return ":20:STMT-PAGED\n:25:NL57ASNB0123456789\n:28C:1/1\n:60F:C260401EUR1000,00\n"
        .":61:2604020402C100,00NTRFPAGE1\n:86:100?32X\n"
        .':62M:'.$intermediate."\n-\n"
        .":20:STMT-PAGED\n:25:NL57ASNB0123456789\n:28C:1/2\n:60M:C260415EUR1100,00\n"
        .":61:2604200420C200,00NTRFPAGE2\n:86:100?32Y\n"
        .':62F:'.$finalBalance."\n-\n";
}

it('leaves the closing balance missing when the final balance tag cannot be read', function (): void {
    iterator_to_array(
        $this->adapter->parse(writeMt940Temp(mt940PagedStatementClosing('C260430EUR1300,000')), $this->resolver),
        preserve_keys: false,
    );

    $meta = $this->adapter->statementMetadata();

    expect($meta)->not->toBeNull();
    expect($meta->closingBalanceMinor)->toBeNull();
    expect($meta->closingBalanceCurrency)->toBeNull();
    expect($meta->closingBalanceDate)->toBeNull();
});

it('records that the closing balance was unreadable rather than never stated', function (): void {
    iterator_to_array(
        $this->adapter->parse(writeMt940Temp(mt940PagedStatementClosing('C260430EUR1300,000')), $this->resolver),
        preserve_keys: false,
    );

    $meta = $this->adapter->statementMetadata();

    expect($meta)->not->toBeNull();
    expect($meta->extras)->toHaveKey('closingBalanceUnreadable');
    expect($meta->extras['closingBalanceUnreadable'])->toBeTrue();
});

it('still imports every row of a statement whose closing balance could not be read', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(writeMt940Temp(mt940PagedStatementClosing('C260430EUR1300,000')), $this->resolver),
        preserve_keys: false,
    );

    expect($dtos)->toHaveCount(2);
});

it('reads the final balance that follows an unreadable intermediate one', function (): void {
    iterator_to_array(
        $this->adapter->parse(
            writeMt940Temp(mt940PagedStatementClosing('C260430EUR1300,00', intermediate: 'C260415EUR1100,000')),
            $this->resolver,
        ),
        preserve_keys: false,
    );

    $meta = $this->adapter->statementMetadata();

    expect($meta)->not->toBeNull();
    expect($meta->closingBalanceMinor)->toBe(130000);
    expect($meta->extras)->not->toHaveKey('closingBalanceUnreadable');
});

it('leaves a statement whose balances all read as it was', function (): void {
    iterator_to_array(
        $this->adapter->parse(writeMt940Temp(mt940PagedStatementClosing('C260430EUR1300,00')), $this->resolver),
        preserve_keys: false,
    );

    $meta = $this->adapter->statementMetadata();

    expect($meta)->not->toBeNull();
    expect($meta->closingBalanceMinor)->toBe(130000);
    expect($meta->closingBalanceDate?->toDateString())->toBe('2026-04-30');
    expect($meta->extras)->not->toHaveKey('closingBalanceUnreadable');
});
