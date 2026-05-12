<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;

dataset('idempotent_adapters', [
    'asn-csv' => [
        'adapterFormat' => 'asn-csv',
        'fixture' => __DIR__.'/../fixtures/asn-sample-1.csv',
        'overlapBase' => __DIR__.'/../fixtures/asn-month-a.csv',
        'overlapNext' => __DIR__.'/../fixtures/asn-month-a-and-b.csv',
    ],
    // Future adapters (CAMT.053, MT940, ICS, PayPal, …) append rows here.
    // The test body is format-agnostic — it depends only on RunsImports.
]);

it('produces zero new rows when the same file is imported twice', function (
    string $adapterFormat,
    string $fixture,
    string $overlapBase,
    string $overlapNext,
): void {
    $this->seedFixtureUserAndAccount();
    $importer = $this->app->make(RunsImports::class);

    $first = $importer->runAndConfirm($fixture, $adapterFormat, $this->fixtureUser);
    $second = $importer->runAndConfirm($fixture, $adapterFormat, $this->fixtureUser);

    expect($first->inserted)->toBeGreaterThan(0);
    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe($first->inserted);
})->with('idempotent_adapters');

it('produces zero new rows when an overlapping period is imported', function (
    string $adapterFormat,
    string $fixture,
    string $overlapBase,
    string $overlapNext,
): void {
    $this->seedFixtureUserAndAccount();
    $importer = $this->app->make(RunsImports::class);

    $first = $importer->runAndConfirm($overlapBase, $adapterFormat, $this->fixtureUser);
    $second = $importer->runAndConfirm($overlapNext, $adapterFormat, $this->fixtureUser);

    expect($second->inserted)->toBeLessThan($first->inserted);
    expect($second->duplicates)->toBeGreaterThan(0);
})->with('idempotent_adapters');
