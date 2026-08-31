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

// SWIFT pages one statement across several messages: :62M:/:60M: are the
// INTERMEDIATE close and open that hand a statement from one page to the next,
// and only :62F: ends it. Treating the intermediate close as the end published
// page one's balance and page one's row count as the whole statement's, so
// /reconcile offered a target the imported rows could never sum to.
function pagedMt940Statement(): string
{
    return ":20:STMT-PAGED\n:25:NL57ASNB0123456789\n:28C:1/1\n:60F:C260401EUR1000,00\n"
        .":61:2604020402C100,00NTRFPAGE1\n:86:100?32X\n"
        .":62M:C260415EUR1100,00\n-\n"
        .":20:STMT-PAGED\n:25:NL57ASNB0123456789\n:28C:1/2\n:60M:C260415EUR1100,00\n"
        .":61:2604200420C200,00NTRFPAGE2\n:86:100?32Y\n"
        .":62F:C260430EUR1300,00\n-\n";
}

it('closes a paged statement on its final balance, not its intermediate one', function (): void {
    iterator_to_array(
        $this->adapter->parse(writeMt940Temp(pagedMt940Statement()), $this->resolver),
        preserve_keys: false,
    );

    $meta = $this->adapter->statementMetadata();

    expect($meta)->not->toBeNull();
    expect($meta->closingBalanceMinor)->toBe(130000);
    expect($meta->closingBalanceDate?->toDateString())->toBe('2026-04-30');
});

it('keeps the opening balance of the first page across the hand-over', function (): void {
    iterator_to_array(
        $this->adapter->parse(writeMt940Temp(pagedMt940Statement()), $this->resolver),
        preserve_keys: false,
    );

    $meta = $this->adapter->statementMetadata();

    expect($meta)->not->toBeNull();
    expect($meta->openingBalanceMinor)->toBe(100000);
    expect($meta->openingBalanceDate?->toDateString())->toBe('2026-04-01');
});

it('counts every entry of a paged statement, and does not call it two statements', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(writeMt940Temp(pagedMt940Statement()), $this->resolver),
        preserve_keys: false,
    );

    $meta = $this->adapter->statementMetadata();

    expect($dtos)->toHaveCount(2);
    expect($meta)->not->toBeNull();
    expect($meta->entryCount)->toBe(2);
    expect($meta->extras)->not->toHaveKey('multiStatement');
});
