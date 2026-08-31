<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\BankAmountParser;
use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };
});

// SWIFT's 15d amount carries the decimal comma whether or not any fractional
// digits follow it, so "1000," is the canonical way to write a whole amount.
it('reads the SWIFT amount whose comma has no digits after it', function (): void {
    expect((new BankAmountParser)->parseMt940Minor('1000,'))->toBe(100000)
        ->and((new BankAmountParser)->parseMt940Minor('0,'))->toBe(0)
        ->and((new BankAmountParser)->parseMt940Minor('-12,'))->toBe(-1200);
});

it('parses a statement whose balance tags are written the canonical way', function (): void {
    $body = ":20:STMT-001\n:25:NL57ASNB0123456789\n:28C:1/1\n:60F:C260202EUR1000,\n"
        .":61:2602020202D50,29NMSCFEE\n:86:Unstructured fee description\n"
        .":62F:C260228EUR949,71\n-\n";

    /** @var Mt940Adapter $adapter */
    $adapter = $this->app->make(Mt940Adapter::class);

    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($adapter->parse(writeMt940Temp($body), $this->resolver), preserve_keys: false);

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->amountMinor)->toBe(-5029);

    $meta = $adapter->statementMetadata();
    expect($meta)->not->toBeNull();
    expect($meta->openingBalanceMinor)->toBe(100000);
    expect($meta->openingBalanceCurrency)->toBe('EUR');
});

// The sniffer had already read the balance tag when this fired, so blaming its
// absence sent the reader hunting for a tag that is right there in the file.
it('does not blame a missing balance tag for a balance it could not read', function (): void {
    $body = ":20:STMT-001\n:25:NL57ASNB0123456789\n:60F:C260202EUR1.000,00\n"
        .":61:2602020202D50,29NMSCFEE\n:86:Unstructured fee description\n"
        .":62F:C260228EUR949,71\n-\n";

    /** @var Mt940Adapter $adapter */
    $adapter = $this->app->make(Mt940Adapter::class);

    $read = fn (): array => iterator_to_array($adapter->parse(writeMt940Temp($body), $this->resolver), preserve_keys: false);

    expect($read)->toThrow(InvalidAmountException::class);

    try {
        $read();
    } catch (InvalidAmountException $e) {
        expect($e->getMessage())->not->toContain('before any balance tag')
            ->and($e->getMessage())->toContain('could not be read');
    }
});

it('still says the balance tag is missing when it really is', function (): void {
    $body = ":20:STMT-001\n:25:NL57ASNB0123456789\n"
        .":61:2602020202D50,29NMSCFEE\n:86:Unstructured fee description\n-\n";

    /** @var Mt940Adapter $adapter */
    $adapter = $this->app->make(Mt940Adapter::class);

    $read = fn (): array => iterator_to_array($adapter->parse(writeMt940Temp($body), $this->resolver), preserve_keys: false);

    try {
        $read();
        expect(false)->toBeTrue('a statement with no balance tag at all must still be refused');
    } catch (InvalidAmountException $e) {
        expect($e->getMessage())->toContain('before any balance tag');
    }
});
