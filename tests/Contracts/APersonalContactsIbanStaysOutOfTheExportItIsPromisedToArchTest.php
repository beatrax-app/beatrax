<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Support\PatternScan;

/** @link ../../.docs/features/counterparties/architecture.md#what-the-personal-contact-banner-promises */

// The counterparty page tells a reader that a personal contact's IBAN stays out
// of exports. It did not: the tax CSV declared a `counterparty_iban` column and
// wrote the decrypted value for every row, personal contacts included, into a
// file the reader hands to an accountant.

/** @return list<string> every shipped file that reads the counterparties table's IBAN column */
function personalIbanReadSites(): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $found = [];
    $seen = 0;

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }

        $seen++;
        $source = (string) file_get_contents($path);

        if (PatternScan::matches("/'counterparties',\s*'iban'|\bcp\.iban\b/", $source)) {
            $found[] = $path;
        }
    }

    expect($seen)->toBeGreaterThan(1000, 'the walk over Modules read almost nothing');

    sort($found);

    return $found;
}

it('reads the counterparties IBAN column in exactly one shipped place', function (): void {
    // Not a style rule. One reader is what makes one type check enough; a
    // second one is a second export, and it has this promise to keep too.
    expect(personalIbanReadSites())->toBe([
        base_path('Modules/Tax/Internal/Services/TaxYearQuery.php'),
    ]);
});

it('withholds it there for the contact type the page names', function (): void {
    foreach (personalIbanReadSites() as $path) {
        $source = (string) file_get_contents($path);

        expect($source)->toContain(
            'CounterpartyType::Personal',
            $path.' reads a counterparty IBAN without asking whether the contact is a personal one',
        );
    }
});

it('still makes the promise this guard keeps', function (): void {
    /** @var Translator $translator */
    $translator = app(Translator::class);

    /** @var string $banner */
    $banner = $translator->get('counterparties::components.privacy_banner.body', [], 'en', false);

    expect($banner)->toContain('exports');
});
