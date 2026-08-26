<?php

declare(strict_types=1);

// A card statement and a wallet export carry no IBAN of the reader's own, so
// the adapters emit a synthetic one and four other places have to recognise it.
// Nothing held those spellings together: ConnectCardStep's comment named a test
// that did not exist, and a rename in one module would have left the others
// looking for an IBAN nobody writes any more — silently, as a prompt that never
// fires and a receipt that never matches.
/** @return list<string> repo-relative production PHP files under Modules/ that contain $literal */
function filesWritingIbanLiteral(string $literal): array
{
    $root = base_path('Modules');
    $needle = "'".$literal."'";
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }
        if (str_contains((string) file_get_contents($path), $needle)) {
            $found[] = str_replace(base_path().'/', '', $path);
        }
    }

    sort($found);

    return $found;
}

it('writes the ICS card own-IBAN with one spelling in every module that knows it', function (): void {
    expect(filesWritingIbanLiteral('ICS-CARD'))->toBe([
        'Modules/Import/Internal/Services/OwnAccountPrompt.php',
        'Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php',
        'Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectCardStep.php',
        'Modules/Receipts/Internal/Matchers/IcsReceiptMatcher.php',
    ], 'A file listed here no longer contains the ICS own-IBAN literal, or a new one does. '
        .'Four modules recognise this value and none of them can import another\'s Internal, so the '
        .'only thing holding the spellings together is this list. Update it deliberately.');
});

it('writes the PayPal wallet own-IBAN with one spelling in every module that knows it', function (): void {
    expect(filesWritingIbanLiteral('PAYPAL'))->toBe([
        'Modules/Import/Public/Actions/EnsurePaypalAccountAction.php',
        'Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php',
        'Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php',
        'Modules/Onboarding/Internal/Http/Livewire/Steps/FirstImportStep.php',
        'Modules/Receipts/Internal/Matchers/PaypalReceiptMatcher.php',
    ], 'A file listed here no longer contains the PayPal own-IBAN literal, or a new one does. '
        .'Five modules recognise this value and none of them can import another\'s Internal, so the '
        .'only thing holding the spellings together is this list. Update it deliberately.');
});

it('goes RED both ways — a new site carrying the literal, and a listed site that stops', function (): void {
    $scratch = base_path('Modules/Core/Internal/OneSpellingPerSyntheticIbanProbe.php');

    file_put_contents($scratch, "<?php\n\nfinal class ScratchProbe { public const IBAN = 'ICS-CARD'; }\n");

    try {
        expect(filesWritingIbanLiteral('ICS-CARD'))
            ->toContain('Modules/Core/Internal/OneSpellingPerSyntheticIbanProbe.php');
    } finally {
        unlink($scratch);
    }

    // The other direction needs no probe: the scan matches the exact quoted
    // literal, so a site renamed to 'ICS-CARD-PRIMARY' drops out of the list
    // the assertion above pins, rather than being silently tolerated.
    expect(filesWritingIbanLiteral('ICS-CARD-PRIMARY'))->toBe([]);
});
