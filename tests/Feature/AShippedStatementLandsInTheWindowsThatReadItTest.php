<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;

$shipped = __DIR__.'/../fixtures/asn-sample-1.csv';

function slwImportAndDetect(string $path, User $user): int
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);
    $importer->runAndConfirm($path, 'asn-csv', $user, formatHint: BankCsvFormatHint::Asn);

    /** @var DispatchesRecurringDetection $detection */
    $detection = app(DispatchesRecurringDetection::class);
    $detection->dispatchForUser((int) $user->id);

    return RecurringSeries::query()->count();
}

beforeEach(function (): void {
    $this->user = $this->seedFixtureUserAndAccount()['user'];
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

dataset('todays', [
    '2026-08-29 09:00:00',
    '2026-12-31 23:30:00',
    '2027-01-01 00:30:00',
    '2027-03-01 12:00:00',
    '2028-02-29 12:00:00',
]);

it('finds no recurring series in the shipped fixture once its dates are out of the detection window', function () use ($shipped): void {
    CarbonImmutable::setTestNow('2026-08-29 09:00:00');

    expect(slwImportAndDetect($shipped, $this->user))->toBe(0);
    expect(Transaction::query()->count())->toBeGreaterThan(200);

    $this->get('/recurring')->assertOk()->assertSee('No recurring activity yet');
    $this->get('/transactions')->assertOk()->assertDontSee('Bol.com');
});

it('shows the rebased rows on the surfaces the shipped fixture leaves empty', function () use ($shipped): void {
    CarbonImmutable::setTestNow('2026-08-29 09:00:00');

    $out = sys_get_temp_dir().'/rebase-'.bin2hex(random_bytes(6)).'.csv';
    expect(Artisan::call('fixture:rebase', ['fixture' => $shipped, '--out' => $out]))->toBe(0);

    slwImportAndDetect($out, $this->user);
    @unlink($out);

    $this->get('/transactions')->assertOk()->assertSee('Bol.com');
    $this->get('/recurring/review')->assertOk()->assertSee('Bol.com');
});

it('finds the fixture monthly series once the statement is rebased onto today', function (string $now) use ($shipped): void {
    CarbonImmutable::setTestNow($now);

    $out = sys_get_temp_dir().'/rebase-'.bin2hex(random_bytes(6)).'.csv';
    $exit = Artisan::call('fixture:rebase', ['fixture' => $shipped, '--out' => $out]);

    expect($exit)->toBe(0);
    expect(is_file($out))->toBeTrue();

    $series = slwImportAndDetect($out, $this->user);
    @unlink($out);

    expect($series)->toBeGreaterThanOrEqual(3);
})->with('todays');
