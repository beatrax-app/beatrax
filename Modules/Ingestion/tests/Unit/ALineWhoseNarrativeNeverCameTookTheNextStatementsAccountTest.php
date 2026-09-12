<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// :86: is optional, so a :61: that never gets one is held back and written out
// by whatever comes next. In a bulk delivery what comes next is the FIRST :61:
// of the following statement -- by which time :25: and :60F: have moved on to
// another account and another currency, and the held line was written under
// both. A euro line came out as the yen statement below it: a hundred times the
// figure, on an account the line was never on.
function heldLineMt940Body(): string
{
    return ":20:HELD-ONE\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFHELD-A\n:86:100?32Payer One\n"
        .":61:2604020402D10,00NTRFHELD-B\n"
        .":62F:C260430EUR1090,00\n-\n"
        .":20:HELD-TWO\n:25:NL91ABNA0417164300\n:60F:C260401JPY500000,\n"
        .":61:2604030403D1000,NTRFHELD-C\n:86:100?32Payer Two\n"
        .":62F:C260430JPY499000,\n-\n";
}

/**
 * @return list<SourceTransactionDto>
 */
function heldLineParse(Mt940Adapter $adapter, AccountResolver $resolver, string $body): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'mt940-held-line-').'.940';
    file_put_contents($tmp, $body);

    try {
        return iterator_to_array($adapter->parse($tmp, $resolver), preserve_keys: false);
    } finally {
        @unlink($tmp);
    }
}

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

it('files a line with no narrative under the account and currency its own statement named', function (): void {
    $dtos = heldLineParse($this->adapter, $this->resolver, heldLineMt940Body());

    $shape = array_map(
        static fn (SourceTransactionDto $d): array => [$d->sourceRef, $d->ownIban, $d->currency, $d->amountMinor],
        $dtos,
    );

    expect($shape)->toBe([
        ['HELD-A', 'NL57ASNB0123456789', 'EUR', 10000],
        ['HELD-B', 'NL57ASNB0123456789', 'EUR', -1000],
        ['HELD-C', 'NL91ABNA0417164300', 'JPY', -1000],
    ]);
});

it('leaves the statement that held the line adding up to the balance it closed on', function (): void {
    heldLineParse($this->adapter, $this->resolver, heldLineMt940Body());
    $meta = $this->adapter->statementMetadata();

    expect($meta->ibanOwner)->toBe('NL57ASNB0123456789')
        ->and($meta->openingBalanceMinor)->toBe(100000)
        ->and($meta->closingBalanceMinor)->toBe(109000)
        ->and($meta->entryCount)->toBe(2)
        ->and($meta->extras)->not->toHaveKey('statementDifferenceMinor');
});

it('files the last line of a file under the statement it belongs to when no narrative closes it', function (): void {
    $dtos = heldLineParse(
        $this->adapter,
        $this->resolver,
        ":20:HELD-TAIL\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401D10,00NTRFTAIL-A\n"
        .":62F:C260430EUR990,00\n-\n",
    );

    expect($dtos)->toHaveCount(1)
        ->and($dtos[0]->ownIban)->toBe('NL57ASNB0123456789')
        ->and($dtos[0]->currency)->toBe('EUR')
        ->and($dtos[0]->amountMinor)->toBe(-1000);
});
