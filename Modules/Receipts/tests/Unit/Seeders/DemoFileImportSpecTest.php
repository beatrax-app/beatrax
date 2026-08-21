<?php

declare(strict_types=1);

use Modules\Receipts\Database\Seeders\Demo\DemoFileImportSpec;

// source_kind is derived from the filename extension rather than carried
// alongside it, so the two cannot drift apart.

it('derives source_kind from an .eml filename', function (): void {
    $spec = new DemoFileImportSpec(
        providerMessageId: 'demo-1',
        sourceFilename: 'demo-paypal-receipt.eml',
        senderEmail: 'service@paypal.com',
        senderName: 'PayPal',
        subject: 'Receipt',
        matcherKey: 'paypal-receipt',
        ageHours: 48,
    );

    expect($spec->sourceKind())->toBe('eml');
    expect($spec->providerMessageId)->toBe('demo-1');
    expect($spec->ageHours)->toBe(48);
});

it('lower-cases the extension when deriving source_kind', function (): void {
    $spec = new DemoFileImportSpec(
        providerMessageId: 'demo-2',
        sourceFilename: 'ARCHIVE.MBOX',
        senderEmail: 'noreply@ics.nl',
        senderName: 'ICS',
        subject: 'Afschrift',
        matcherKey: 'ics-receipt',
        ageHours: 72,
    );

    expect($spec->sourceKind())->toBe('mbox');
});
