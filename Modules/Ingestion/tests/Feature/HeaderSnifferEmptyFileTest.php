<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvHeaderProfile;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvLanguageProfile;
use Modules\Ingestion\Public\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Services\HeaderSniffer;

beforeEach(function (): void {
    $this->sniffer = $this->app->make(HeaderSniffer::class);
});

// A zero-byte upload survives the extension check, and each sniff path reads
// its own first line, so one shared case would leave two branches unexercised.
it('rejects an empty file on every CSV sniff path', function (string $format): void {
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-empty-').'.csv';
    file_put_contents($tmp, '');

    try {
        expect(fn () => $this->sniffer->sniff($tmp, $format))
            ->toThrow(SniffMismatchException::class, 'The file is empty.');
    } finally {
        @unlink($tmp);
    }
})->with([
    'preset CSV' => 'n26-csv',
    'PayPal' => PaypalCsvLanguageProfile::FORMAT,
    'ASN' => AsnCsvHeaderProfile::FORMAT,
]);
