<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Internal\Exceptions\ImportAlreadyConfirmedException;
use Modules\Import\Public\Actions\DiscardImport;
use Modules\Ledger\Models\ImportRun;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
});

it('flips a previewed import to discarded', function (): void {
    /** @var ImportRun $run */
    $run = ImportRun::create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var DiscardImport $action */
    $action = $this->app->make(DiscardImport::class);
    $action($run->id, $this->fixtureUser);

    expect(ImportRun::query()->find($run->id)?->status)->toBe('discarded');
});

it('refuses to discard an already-confirmed import run', function (): void {
    /** @var ImportRun $run */
    $run = ImportRun::create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'confirmed_at' => CarbonImmutable::now(),
        'inserted_count' => 12,
        'status' => 'confirmed',
    ]);

    /** @var DiscardImport $action */
    $action = $this->app->make(DiscardImport::class);

    expect(fn () => $action($run->id, $this->fixtureUser))
        ->toThrow(ImportAlreadyConfirmedException::class);

    expect(ImportRun::query()->find($run->id)?->status)->toBe('confirmed');
});
