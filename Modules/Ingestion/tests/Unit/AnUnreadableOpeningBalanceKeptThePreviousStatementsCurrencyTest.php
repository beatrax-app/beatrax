<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// A bulk delivery holds one statement per account, and each states its own
// currency on its :60F:. The currency was only ever written when that line
// parsed, so a yen statement whose opening balance carries no magnitude was
// read at the euro statement above it: `:61:2604030403D1000,` came out -100000
// EUR where the file says JPY 1000 -- a hundred times the figure, in the wrong
// denomination, and the account's own currency never appeared in the run.
function carriedCurrencyMt940Body(string $yenOpening): string
{
    return ":20:CARRIED-EUR\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFA-1\n:86:100?32X\n"
        .":61:2604020402D50,00NTRFA-2\n:86:100?32Y\n"
        .":62F:C260430EUR1050,00\n-\n"
        .":20:CARRIED-JPY\n:25:NL91ABNA0417164300\n:60F:{$yenOpening}\n"
        .":61:2604030403D1000,NTRFB-1\n:86:100?32Z\n"
        .":62F:C260430JPY499000,\n-\n";
}

function carriedCurrencyWriteTemp(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'carried-currency-').'.940';
    file_put_contents($path, $body);

    return $path;
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

it('refuses the rows under an opening balance it could not read', function (): void {
    // A :60F: whose 15d magnitude is missing entirely: the tag is there, the
    // currency it would have set is not.
    $path = carriedCurrencyWriteTemp(carriedCurrencyMt940Body('C260401JPY'));

    try {
        $yielded = [];
        $thrown = null;

        try {
            foreach ($this->adapter->parse($path, $this->resolver) as $dto) {
                $yielded[] = [$dto->ownIban, $dto->currency, $dto->amountMinor];
            }
        } catch (InvalidAmountException $e) {
            $thrown = $e;
        }

        // The euro statement above is complete and streams in full; only the
        // statement whose own header could not be read is refused.
        expect($yielded)->toBe([
            ['NL57ASNB0123456789', 'EUR', 10000],
            ['NL57ASNB0123456789', 'EUR', -5000],
        ]);
        expect($thrown)->toBeInstanceOf(InvalidAmountException::class)
            ->and($thrown?->getMessage())->toBe(
                'MT940 :61: encountered before a currency was set; the balance tag present could not be read.'
            );
    } finally {
        @unlink($path);
    }
});

it('reads the same yen statement at its own scale when its opening balance parses', function (): void {
    $path = carriedCurrencyWriteTemp(carriedCurrencyMt940Body('C260401JPY500000,'));

    try {
        $dtos = iterator_to_array($this->adapter->parse($path, $this->resolver), preserve_keys: false);

        $shape = array_map(
            static fn (SourceTransactionDto $d): array => [$d->ownIban, $d->currency, $d->amountMinor],
            $dtos,
        );

        // -1000, never -100000: the same :61: line at the two scales is the
        // whole of what the carried currency cost.
        expect($shape)->toBe([
            ['NL57ASNB0123456789', 'EUR', 10000],
            ['NL57ASNB0123456789', 'EUR', -5000],
            ['NL91ABNA0417164300', 'JPY', -1000],
        ]);
    } finally {
        @unlink($path);
    }
});

it('keeps a paged statement reading at its own currency across an unreadable :60M:', function (): void {
    // :60M: reopens the statement :62M: handed over, so its currency is already
    // known from the :60F: above and an unreadable one erases nothing.
    $body = ":20:PAGED\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFP-1\n:86:100?32X\n"
        .":62M:C260415EUR1100,00\n"
        .":20:PAGED\n:25:NL57ASNB0123456789\n:60M:C260415EUR\n"
        .":61:2604160416D25,00NTRFP-2\n:86:100?32Y\n"
        .":62F:C260430EUR1075,00\n-\n";
    $path = carriedCurrencyWriteTemp($body);

    try {
        $dtos = iterator_to_array($this->adapter->parse($path, $this->resolver), preserve_keys: false);

        $shape = array_map(
            static fn (SourceTransactionDto $d): array => [$d->currency, $d->amountMinor],
            $dtos,
        );

        expect($shape)->toBe([['EUR', 10000], ['EUR', -2500]]);
    } finally {
        @unlink($path);
    }
});
