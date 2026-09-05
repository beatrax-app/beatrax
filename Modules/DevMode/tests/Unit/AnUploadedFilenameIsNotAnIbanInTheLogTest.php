<?php

declare(strict_types=1);

use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * @param  array<string, mixed>  $context
 */
function uploadFilenameRecord(array $context): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Error,
        message: 'UploadWizard: import preview failed.',
        context: $context,
    );
}

// The bank names the file for the account it covers, and UploadFilename only
// folds characters that could climb out of a path — an IBAN passes through it
// byte for byte, which is what reaches the log.
it('redacts the uploaded filename an import failure logs', function (): void {
    $processor = new RedactSecretsProcessor;

    $out = $processor(uploadFilenameRecord([
        'source_format' => 'ing-csv',
        'filename' => 'ING_NL91ABNA0417164300_2026.csv',
    ]));

    expect($out->context['filename'])->toBe('[REDACTED]')
        ->and($out->context['source_format'])->toBe('ing-csv');
});

it('redacts every spelling the upload paths use for the same value', function (string $key): void {
    $processor = new RedactSecretsProcessor;

    $out = $processor(uploadFilenameRecord([$key => 'Rekeningoverzicht_Jane_Doe_NL91ABNA0417164300.csv']));

    expect($out->context[$key])->toBe('[REDACTED]');
})->with(['filename', 'file_name', 'original_filename', 'source_filename', 'FileName', 'original-filename']);

it('still redacts a credential key now that a second list exists', function (): void {
    $processor = new RedactSecretsProcessor;

    $out = $processor(uploadFilenameRecord(['authorization' => 'Bearer abc123def456_xyz', 'refresh_token' => 'x']));

    expect($out->context['authorization'])->toBe('[REDACTED]')
        ->and($out->context['refresh_token'])->toBe('[REDACTED]');
});

it('leaves an ordinary context key alone', function (): void {
    $processor = new RedactSecretsProcessor;

    $out = $processor(uploadFilenameRecord(['import_run_id' => 42, 'exception_class' => 'RuntimeException']));

    expect($out->context['import_run_id'])->toBe(42)
        ->and($out->context['exception_class'])->toBe('RuntimeException');
});
