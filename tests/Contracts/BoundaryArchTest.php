<?php

declare(strict_types=1);

// Arch tests powered by pest-plugin-arch. Empty module skeletons trivially
// satisfy these rules; once subsequent plans add real code the assertions bind.

arch('Modules\\Ledger\\Internal is only used inside Modules\\Ledger')
    ->expect('Modules\\Ledger\\Internal')
    ->toOnlyBeUsedIn('Modules\\Ledger');

arch('Modules\\Core\\Internal is only used inside Modules\\Core')
    ->expect('Modules\\Core\\Internal')
    ->toOnlyBeUsedIn('Modules\\Core');

arch('Modules\\Ingestion\\Internal is only used inside Modules\\Ingestion')
    ->expect('Modules\\Ingestion\\Internal')
    ->toOnlyBeUsedIn('Modules\\Ingestion');

arch('Modules\\Import\\Internal is only used inside Modules\\Import')
    ->expect('Modules\\Import\\Internal')
    ->toOnlyBeUsedIn('Modules\\Import');

arch('Modules\\Categorization\\Internal is only used inside Modules\\Categorization')
    ->expect('Modules\\Categorization\\Internal')
    ->toOnlyBeUsedIn('Modules\\Categorization');

arch('no Laravel facade usage in module code')
    ->expect('Illuminate\\Support\\Facades')
    ->not->toBeUsedIn('Modules');

arch('Money\\Money types stay inside the ASN adapter folder')
    ->expect('Money\\Money')
    ->toOnlyBeUsedIn('Modules\\Ingestion\\Internal\\Adapters\\Asn');

arch('RederiveFingerprintsCommand is never imported by any HTTP or routing namespace')
    ->expect('Modules\\Ledger\\Internal\\Console\\RederiveFingerprintsCommand')
    ->not->toBeUsedIn([
        'Modules\\Ledger\\Internal\\Http',
        'Modules\\Ledger\\Public\\Http',
        'Modules\\Ledger\\Routes',
        'Modules\\Core\\Internal\\Http',
        'Modules\\Core\\Public\\Http',
        'Modules\\Ingestion\\Internal\\Http',
        'Modules\\Ingestion\\Public\\Http',
        'Modules\\Import\\Internal\\Http',
        'Modules\\Import\\Public\\Http',
        'Modules\\Categorization\\Internal\\Http',
        'Modules\\Categorization\\Public\\Http',
    ]);

it('does not allow a paypal-api route or class to exist (noPaypalApiRoute)', function (): void {
    // Defensive arch invariant: the PayPal Reporting API integration is
    // deferred behind a business-account upgrade trigger. If a future task
    // accidentally lands a paypal-api adapter, a Reporting API client class,
    // or a route segment using `paypal-api`, this test fails loudly. The
    // grep strips `/* ... */` and `// ...` comments first so legitimate
    // PHPDoc references stay legal.
    $hits = [];

    foreach (['routes', 'Modules'] as $root) {
        $absoluteRoot = base_path($root);
        if (! is_dir($absoluteRoot)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $absoluteRoot,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (preg_match('/\.(php|blade\.php)$/', $path) !== 1) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
            if (preg_match('/PaypalApiAdapter|PaypalReportingApi|paypal-api/i', $stripped) === 1) {
                $hits[] = $path;
            }
        }
    }

    expect($hits)->toBe(
        [],
        "No paypal-api route or class should exist. Found in:\n  ".implode("\n  ", $hits)
    );
});
