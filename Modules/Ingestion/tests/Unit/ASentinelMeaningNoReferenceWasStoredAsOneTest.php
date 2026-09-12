<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;

// SWIFT fills :61: field 7 with NONREF where a row carries no reference, the
// way SEPA fills an end-to-end field with NOTPROVIDED. Stored as a reference,
// the sentinel is a lookup key every such row of the account shares.
beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();

    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->adapter = $this->app->make(Mt940Adapter::class);

    $this->parseStatement = function (string $line): object {
        $path = tempnam(sys_get_temp_dir(), 'mt940-sentinel').'.sta';

        file_put_contents($path, implode("\n", [
            ':20:ASN-SYN-MT940-SENTINEL',
            ':25:NL57ASNB0123456789',
            ':28C:00001/00001',
            ':60F:C260217EUR1000,00',
            $line,
            ':86:005?20Cafe Rialto Utrecht?31NL19BANK0000000010?32Cafe Rialto',
            ':62F:C260217EUR987,01',
        ])."\n");

        $rows = iterator_to_array($this->adapter->parse($path, $this->resolver), preserve_keys: false);
        unlink($path);

        expect($rows)->toHaveCount(1);

        return $rows[0];
    };
});

it('stores no reference for a row whose only reference is the swift sentinel', function (): void {
    expect(($this->parseStatement)(':61:2602170217D12,99NTRFNONREF')->sourceRef)->toBeNull();
});

it('stores no reference for the sepa sentinel either', function (): void {
    expect(($this->parseStatement)(':61:2602170217D12,99NTRFNOTPROVIDED')->sourceRef)->toBeNull();
});

it('stores the reference a row does carry', function (): void {
    expect(($this->parseStatement)(':61:2602170217D12,99NTRF4471902')->sourceRef)->toBe('4471902');
});
