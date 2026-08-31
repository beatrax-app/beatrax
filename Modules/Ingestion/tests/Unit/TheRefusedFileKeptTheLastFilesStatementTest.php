<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Internal\Adapters\Ics\IcsAmountParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsDateParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\IcsStatementHeader;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// Every adapter that carries statement metadata is a container singleton, and
// each answers statementMetadata() with "the most recent parse() run's". A run
// refused at the sniff cleared nothing, so the question was then answered with
// the statement of the file BEFORE it — a period, a pair of balances and an
// entry count belonging to a different upload.
beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };
});

function refusedFileWithSuffix(string $suffix, string $body): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'refused-').$suffix;
    file_put_contents($tmp, $body);
    register_shutdown_function(static function () use ($tmp): void {
        @unlink($tmp);
    });

    return $tmp;
}

// The real sniffer with a text-fixture extractor: the refusal under test is the
// sniff's, and shelling out to pdftotext would add a second reason to fail.
function statefulAdapterFor(string $format): SourceAdapter
{
    if ($format === SourceFormat::IcsPdf->value) {
        $extractor = new class extends PdfTextExtractor
        {
            public function extract(string $pdfPath): string
            {
                return (string) file_get_contents(
                    base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt'),
                );
            }
        };

        return new IcsPdfAdapter(
            app(HeaderSniffer::class),
            $extractor,
            new IcsAmountParser,
            new IcsStatementHeader(new IcsDateParser),
        );
    }

    return match ($format) {
        SourceFormat::Camt053->value => app(Camt053Adapter::class),
        SourceFormat::Mt940->value => app(Mt940Adapter::class),
        default => app(PaypalCsvAdapter::class),
    };
}

it('forgets the previous statement when the next file is refused at the sniff', function (
    string $format,
    string $goodFixture,
    string $badSuffix,
    string $badBody,
): void {
    $adapter = statefulAdapterFor($format);

    iterator_to_array($adapter->parse(base_path($goodFixture), $this->resolver), preserve_keys: false);
    expect($adapter->statementMetadata())->not->toBeNull();

    $refused = refusedFileWithSuffix($badSuffix, $badBody);
    expect(fn () => iterator_to_array($adapter->parse($refused, $this->resolver), preserve_keys: false))
        ->toThrow(SniffMismatchException::class);

    expect($adapter->statementMetadata())->toBeNull();
})->with([
    'camt053' => [
        SourceFormat::Camt053->value,
        'tests/fixtures/asn-camt053-sample-1.xml',
        '.xml',
        '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.02"/>',
    ],
    'mt940' => [
        SourceFormat::Mt940->value,
        'tests/fixtures/asn-mt940-sample-1.sta',
        '.sta',
        "Datum,Bedrag\n02-02-2026,-3.99\n",
    ],
    'ics-pdf' => [
        SourceFormat::IcsPdf->value,
        'Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf',
        '.pdf',
        "not a pdf at all\n",
    ],
    'paypal-csv' => [
        SourceFormat::PaypalCsv->value,
        'Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv',
        '.txt',
        "Datum,Tijd\n",
    ],
]);
