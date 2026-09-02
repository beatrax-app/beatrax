<?php

declare(strict_types=1);

// A card statement and a wallet export carry no IBAN of the reader's own, so
// the adapter emits a synthetic one and four other modules have to recognise
// it. They now read it from Modules\Ingestion\Public\Enums\SyntheticIban, and
// this guard is what keeps a fifth site from spelling it by hand again — a
// rename on one side of a hand-written pair is silent: a prompt that never
// fires and a receipt that never matches.
// $root is injectable for one reason only: the red-proof below needs a tree it
// can plant a file in, and planting under Modules/ races every other arch guard
// scanning that tree in a parallel worker.
/** @return list<string> production PHP files under $root, relative to its parent, that contain $literal */
function filesWritingIbanLiteral(string $literal, ?string $root = null): array
{
    $root ??= base_path('Modules');
    $relativeTo = dirname($root).'/';
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
            $found[] = str_replace($relativeTo, '', $path);
        }
    }

    sort($found);

    return $found;
}

it('writes the ICS card own-IBAN with one spelling in every module that knows it', function (): void {
    expect(filesWritingIbanLiteral('ICS-CARD'))->toBe([
        'Modules/Ingestion/Public/Enums/SyntheticIban.php',
    ], 'The ICS own-IBAN is spelled in SyntheticIban and read from there. A new file writing the '
        .'literal is a second spelling that a rename would leave behind. Point it at the enum '
        .'rather than adding a line here.');
});

it('writes the PayPal wallet own-IBAN with one spelling in every module that knows it', function (): void {
    expect(filesWritingIbanLiteral('PAYPAL'))->toBe([
        'Modules/Ingestion/Public/Enums/SyntheticIban.php',
        // Not the IBAN: the account-kind badge the first-import step prints
        // beside an account name, which happens to be the same six letters.
        'Modules/Onboarding/Internal/Http/Livewire/Steps/FirstImportStep.php',
    ], 'The PayPal own-IBAN is spelled in SyntheticIban and read from there. A new file writing the '
        .'literal is a second spelling that a rename would leave behind. Point it at the enum '
        .'rather than adding a line here.');
});

// The demo dataset stands its own wallet in, and deliberately not with the
// sentinel above: a demo account claiming 'PAYPAL' would answer the naming
// prompt of the reader's first real PayPal import and swallow its rows. So the
// demo spelling gets the same treatment one layer down — pinned, not derived.
it('writes the demo wallet identifier with one spelling across the demo seeders', function (): void {
    expect(filesWritingIbanLiteral('PAYPAL-DEMO-1'))->toBe([
        'Modules/Counterparties/Database/Seeders/Demo/DemoCounterpartiesSeeder.php',
        'Modules/Ledger/Database/Seeders/Demo/DemoAccountsSeeder.php',
        'Modules/Ledger/Database/Seeders/Demo/DemoTransactionsSeeder.php',
        'Modules/Ledger/Database/Seeders/Demo/DemoTransferPairsSeeder.php',
    ], 'The demo wallet is one account referenced from three other seeders: the transfer pair, '
        .'the top-up row and the self-account counterparty all join to it by this identifier, '
        .'across a module boundary that forbids sharing a constant. Renaming it in one file '
        .'leaves the others pointing at an account that does not exist, and the demo dataset '
        .'still seeds green. Change all four together, or none.');
});

it('goes RED both ways — a new site carrying the literal, and a listed site that stops', function (): void {
    // Deliberately not under Modules/: while a probe exists there, any guard
    // enumerating that tree in another worker lists it and then finds it gone,
    // and goes red naming a file it has no business reading.
    $root = sys_get_temp_dir().'/one-spelling-'.bin2hex(random_bytes(6));
    $probe = $root.'/OneSpellingPerSyntheticIbanProbe.php';

    mkdir($root, 0o777, true);
    file_put_contents($probe, "<?php\n\nfinal class ScratchProbe { public const IBAN = 'ICS-CARD'; }\n");

    try {
        expect(filesWritingIbanLiteral('ICS-CARD', $root))
            ->toContain(basename($root).'/OneSpellingPerSyntheticIbanProbe.php');

        // The other direction, against the same planted file: the scan matches
        // the exact quoted literal, so a site renamed to 'ICS-CARD-PRIMARY'
        // drops out of the pinned list rather than being silently tolerated.
        expect(filesWritingIbanLiteral('ICS-CARD-PRIMARY', $root))->toBe([]);
    } finally {
        unlink($probe);
        rmdir($root);
    }
});
