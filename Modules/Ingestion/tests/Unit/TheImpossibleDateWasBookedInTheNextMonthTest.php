<?php

declare(strict_types=1);

use Modules\Core\Public\Support\SafeDate;
use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\IcsDateParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalDateParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Internal\Exceptions\InvalidDateException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;

// createFromFormat() rolls an out-of-range component forward rather than
// refusing it, and only PeriodPresetResolver ever guarded against that. Every
// ingestion adapter took the roll: 31 February booked itself on 3 March, and a
// zero day walked the row back into the previous year.

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };
});

it('refuses a day the month does not have', function (string $format, string $raw): void {
    expect(SafeDate::fromFormatOrNull($format, $raw))->toBeNull();
})->with([
    'asn 31 February' => ['!d-m-Y', '31-02-2026'],
    'asn 29 February in a common year' => ['!d-m-Y', '29-02-2026'],
    'asn 32 January' => ['!d-m-Y', '32-01-2026'],
    'asn day zero walks into last year' => ['!d-m-Y', '00-01-2026'],
    'asn month thirteen walks into next year' => ['!d-m-Y', '01-13-2026'],
    'mt940 six-digit 31 February' => ['!ymd', '260231'],
    'iso 31 February' => ['!Y-m-d', '2026-02-31'],
    'us numeric 31 February' => ['!n/j/Y', '2/31/2026'],
]);

it('keeps every real date the adapters already parse', function (string $format, string $raw, string $expected): void {
    expect(SafeDate::fromFormatOrNull($format, $raw)?->toDateString())->toBe($expected);
})->with([
    'asn ordinary day' => ['!d-m-Y', '01-08-2026', '2026-08-01'],
    'asn month end' => ['!d-m-Y', '31-08-2026', '2026-08-31'],
    'asn leap day in a leap year' => ['!d-m-Y', '29-02-2028', '2028-02-29'],
    'mt940 six-digit' => ['!ymd', '260805', '2026-08-05'],
    'iso' => ['!Y-m-d', '2026-08-05', '2026-08-05'],
    'us numeric unpadded' => ['!n/j/Y', '2/5/2026', '2026-02-05'],
    'us numeric zero-padded against an unpadded format' => ['!n/j/Y', '02/05/2026', '2026-02-05'],
]);

it('refuses an ASN row dated 31 February rather than booking it in March', function (): void {
    $header = 'Datum,Je rekening,Van / naar,Naam,Adres,Postcode,Woonplaats,Valuta saldo,'
        .'Saldo voor boeking,Valuta,Bedrag bij / af,Verwerkingsdatum,Valutadatum,Code,Type,'
        .'Volgnummer,Betalingskenmerk,Omschrijving,Afschriftnummer,Categorie';
    $row = '31-02-2026,NL57ASNB0123456789,NL10BANK0000000101,Onmogelijke Datum BV,,,,EUR,'
        ."1000.00,EUR,-11.00,31-02-2026,31-02-2026,9714,EIC,900001,,'Een datum die niet bestaat',9,'Overig'";

    $path = tempnam(sys_get_temp_dir(), 'asn').'.csv';
    file_put_contents($path, $header."\n".$row."\n");

    try {
        expect(fn () => iterator_to_array(
            $this->app->make(AsnCsvAdapter::class)->parse($path, $this->resolver),
            preserve_keys: false,
        ))->toThrow(InvalidAmountException::class);
    } finally {
        @unlink($path);
    }
});

it('refuses an impossible PayPal date in either shape it accepts', function (string $raw): void {
    expect(fn () => (new PaypalDateParser)->parse($raw))->toThrow(InvalidDateException::class);
})->with([
    'us numeric' => ['2/31/2026'],
    'iso' => ['2026-02-31'],
]);

it('refuses an impossible ICS date', function (): void {
    expect(fn () => (new IcsDateParser)->parse('31-02-2026'))->toThrow(InvalidAmountException::class);
});
