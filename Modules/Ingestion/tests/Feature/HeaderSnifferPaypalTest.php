<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvLanguageProfile;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Internal\Exceptions\UnsupportedPaypalCsvLanguageException;
use Modules\Ingestion\Public\Dto\SniffResult;
use Modules\Ingestion\Public\Services\HeaderSniffer;

beforeEach(function (): void {
    $this->sniffer = $this->app->make(HeaderSniffer::class);
});

it('accepts the redacted PayPal fixture and reports its profile', function (): void {
    $result = $this->sniffer->sniff(
        base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv'),
        PaypalCsvLanguageProfile::FORMAT,
    );

    expect($result)->toBeInstanceOf(SniffResult::class);
    expect($result->format)->toBe(PaypalCsvLanguageProfile::FORMAT);
    expect($result->delimiter)->toBe(PaypalCsvLanguageProfile::DELIMITER);
    expect($result->hasHeader)->toBe(PaypalCsvLanguageProfile::HAS_HEADER);
    expect($result->encoding)->toBe(PaypalCsvLanguageProfile::SOURCE_ENCODING);
})->group('phase-4');

it('rejects a non-CSV extension declared as paypal-csv with a user-readable message', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-pp-bad-ext-').'.txt';
    file_put_contents($tmp, "Datum,Tijd\n4/1/2026,12:00:00\n");

    try {
        expect(fn () => $this->sniffer->sniff($tmp, PaypalCsvLanguageProfile::FORMAT))
            ->toThrow(SniffMismatchException::class, "doesn't look like a CSV");
    } finally {
        @unlink($tmp);
    }
})->group('phase-4');

it('rejects a CSV whose header tokens do not match any registered language profile', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-pp-unknown-lang-').'.csv';
    // Plausible non-PayPal CSV — same delimiter, no PayPal discriminator tokens.
    file_put_contents($tmp, "Foo,Bar,Baz\n1,2,3\n");

    try {
        expect(fn () => $this->sniffer->sniff($tmp, PaypalCsvLanguageProfile::FORMAT))
            ->toThrow(UnsupportedPaypalCsvLanguageException::class);
    } finally {
        @unlink($tmp);
    }
})->group('phase-4');

it('rejects an unreadable / non-existent file declared as paypal-csv', function (): void {
    expect(fn () => $this->sniffer->sniff('/no/such/path-xyz.csv', PaypalCsvLanguageProfile::FORMAT))
        ->toThrow(SniffMismatchException::class);
})->group('phase-4');
