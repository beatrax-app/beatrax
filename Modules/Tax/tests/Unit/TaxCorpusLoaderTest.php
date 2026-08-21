<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Modules\Tax\Internal\Corpus\TaxCorpusLoader;
use Psr\Log\LoggerInterface;

it('loads the Dutch corpus and returns a non-empty list with key and name', function (): void {
    /** @var TaxCorpusLoader $loader */
    $loader = app(TaxCorpusLoader::class);

    $entries = $loader->loadForCountry('nl');

    expect($entries)->not->toBeEmpty();
    foreach ($entries as $entry) {
        expect($entry)->toHaveKey('key');
        expect($entry)->toHaveKey('name');
        expect((string) $entry['key'])->not->toBeEmpty();
        expect((string) $entry['name'])->not->toBeEmpty();
    }
});

it('returns an empty list for an unsupported country code', function (): void {
    /** @var TaxCorpusLoader $loader */
    $loader = app(TaxCorpusLoader::class);

    expect($loader->loadForCountry('xx'))->toBeEmpty();
    expect($loader->loadForCountry(''))->toBeEmpty();
});

it('all six shipped country files parse and yield at least 3 entries each', function (string $cc): void {
    /** @var TaxCorpusLoader $loader */
    $loader = app(TaxCorpusLoader::class);

    $entries = $loader->loadForCountry($cc);

    expect(count($entries))->toBeGreaterThanOrEqual(3);
    foreach ($entries as $entry) {
        expect($entry)->toHaveKey('key');
        expect($entry)->toHaveKey('name');
    }
})->with(['at', 'be', 'cy', 'cz', 'de', 'dk', 'ee', 'es', 'fi', 'fr', 'gb', 'gr', 'ie', 'is', 'it', 'lt', 'lu', 'lv', 'mt', 'nl', 'no', 'pl', 'pt', 'se', 'sk', 'us']);

it('logs a warning and returns empty list when a known country has no corpus file', function (): void {
    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once();

    $loader = new TaxCorpusLoader($logger);

    // Moved aside, not deleted: the enum must still accept the code while the
    // file is absent.
    $corpusPath = resource_path('corpus/tax/us.yaml');
    $stashed = $corpusPath.'.stash';
    rename($corpusPath, $stashed);

    try {
        expect($loader->loadForCountry('us'))->toBeEmpty();
    } finally {
        rename($stashed, $corpusPath);
    }
});

it('logs a warning and returns empty list when the YAML has no entries list', function (): void {
    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once();

    $loader = new TaxCorpusLoader($logger);

    // Valid YAML that parses cleanly but carries no `entries:` root key.
    $corpusPath = resource_path('corpus/tax/nl.yaml');
    $backup = file_get_contents($corpusPath);
    assert(is_string($backup));
    file_put_contents($corpusPath, "meta:\n  country: nl\n");

    try {
        expect($loader->loadForCountry('nl'))->toBeEmpty();
    } finally {
        file_put_contents($corpusPath, $backup);
    }
});

it('logs a warning and returns empty list when the corpus file contains malformed YAML', function (): void {
    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once();

    $loader = new TaxCorpusLoader($logger);

    $corpusPath = resource_path('corpus/tax/nl.yaml');
    $backup = file_get_contents($corpusPath);
    assert(is_string($backup));

    // A native !!php/object tag: rejected only because the loader passes
    // PARSE_EXCEPTION_ON_INVALID_TYPE.
    file_put_contents($corpusPath, "entries:\n  - !!php/object 'O:8:\"stdClass\":0:{}'");

    try {
        $entries = $loader->loadForCountry('nl');
        expect($entries)->toBeEmpty();
    } finally {
        file_put_contents($corpusPath, $backup);
    }
});
