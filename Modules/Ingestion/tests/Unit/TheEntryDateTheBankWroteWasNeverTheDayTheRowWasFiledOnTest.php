<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;

// SWIFT :61: states the value date first and the booking (entry) date second.
// Every other adapter files a row on the day the bank BOOKED it and keeps the
// value date beside it; MT940 used the value date for all three, so a charge
// booked in February with a January value date left the month it belongs to,
// and the same row read from two of one bank's own exports hashed twice.
beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->mt940 = $this->app->make(Mt940Adapter::class);
});

function entryDateMt940(string $entryLine): string
{
    $path = tempnam(sys_get_temp_dir(), 'entry-date-').'.940';
    file_put_contents(
        $path,
        ":20:ENTRY-DATE\n:25:NL57ASNB0123456789\n:60F:C260201EUR1000,00\n"
            .$entryLine."\n:86:020?20SVWZ+interest\n"
            .":62F:C260228EUR999,02\n-\n",
    );
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });

    return $path;
}

it('files a row on the day the bank booked it, not the day the money was valued', function (): void {
    $dtos = iterator_to_array(
        $this->mt940->parse(entryDateMt940(':61:2602010205D0,98NMSC20260205-227303'), $this->resolver),
        preserve_keys: false,
    );

    expect($dtos)->toHaveCount(1)
        ->and($dtos[0]->bookedAt->toDateString())->toBe('2026-02-05')
        ->and($dtos[0]->postedAt->toDateString())->toBe('2026-02-05')
        ->and($dtos[0]->valueDate->toDateString())->toBe('2026-02-01');
});

it('falls back to the value date when the line states no entry date', function (): void {
    $dtos = iterator_to_array(
        $this->mt940->parse(entryDateMt940(':61:260201D0,98NMSC20260205-227303'), $this->resolver),
        preserve_keys: false,
    );

    expect($dtos)->toHaveCount(1)
        ->and($dtos[0]->bookedAt->toDateString())->toBe('2026-02-01')
        ->and($dtos[0]->postedAt->toDateString())->toBe('2026-02-01')
        ->and($dtos[0]->valueDate->toDateString())->toBe('2026-02-01');
});

// Both files are the same real anonymised ASN statement, committed as the
// repository's gold pair. The overdraft-interest row is the one whose value and
// booking days differ, and it is the row the two parsers disagreed about.
it('dates the interest row of the gold MT940 as the gold CSV dates it', function (): void {
    $mtRow = theOnlyRowOfAmount($this->mt940->parse(base_path('tests/fixtures/asn-mt940-sample-1.sta'), $this->resolver), -98);

    $csv = $this->app->make(SourceAdapterRegistry::class)->for(CsvPresetRegistry::ASN);
    $csvRow = theOnlyRowOfAmount($csv->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver), -98);

    expect($mtRow->postedAt->toDateString())->toBe($csvRow->postedAt->toDateString())
        ->and($mtRow->bookedAt->toDateTimeString())->toBe($csvRow->bookedAt->toDateTimeString())
        ->and($mtRow->valueDate->toDateString())->toBe($csvRow->valueDate->toDateString())
        ->and($csvRow->postedAt->toDateString())->toBe('2026-02-05')
        ->and($csvRow->valueDate->toDateString())->toBe('2026-02-01');
});

/**
 * @param  iterable<int, SourceTransactionDto>  $rows
 */
function theOnlyRowOfAmount(iterable $rows, int $amountMinor): SourceTransactionDto
{
    $found = [];
    foreach ($rows as $row) {
        if ($row->amountMinor === $amountMinor) {
            $found[] = $row;
        }
    }

    expect($found)->toHaveCount(1);

    return $found[0];
}
