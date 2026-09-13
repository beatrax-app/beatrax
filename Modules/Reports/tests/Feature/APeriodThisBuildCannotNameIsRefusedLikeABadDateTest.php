<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Reports\Internal\Enums\ReportPeriodPreset;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

// Two halves of one defect, walked at 1440x900 and 390x844.
//
// /reports?period=since_the_dawn_of_time answered 200 and drew a whole report
// over the default period — a EUR 2,155.13 total — while keeping the unusable
// word in the URL and leaving every button in the Period group unpressed. The
// reader was shown a figure and no indication of what it covered. The from/to
// rail beside it refuses the same class of input with a message.
//
// And the refusal it refuses with was drawn in `srch-no-results`, which is the
// empty state two branches below it in the same template: body colour, no
// tone, nothing marking the control that caused it. A refusal a reader cannot
// tell from an absence is not a refusal.

function unnamedPeriodUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'unnamed-period-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function unnamedPeriodBuilder(string $period): RenderedMarkup
{
    return RenderedMarkup::of(
        Livewire::withQueryParams(['period' => $period])->test(ReportBuilder::class)->html(),
    );
}

// A ledger with rows in it, so "no table was drawn" is a fact about the refusal
// rather than about an empty account: over an empty ledger the page draws the
// empty state whether it refused anything or not.
function unnamedPeriodLedger(User $user): void
{
    $connection = app(DatabaseManager::class)->connection();

    $accountId = $connection->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'unnamed-period-'.$user->id,
        'kind' => 'bank',
        'iban' => 'NL00PERIOD'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $connection->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/unnamed-period-'.$user->id.'.csv',
        'sha256' => hash('sha256', 'unnamed-period-'.$user->id),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $today = CarbonImmutable::now()->startOfMonth()->addDay()->toDateString();

    $connection->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'unnamed-period-'.$user->id),
        'posted_at' => $today,
        'booked_at' => $today.' 00:00:00',
        'value_date' => $today,
        'amount_minor' => -2_155_13,
        'currency' => 'EUR',
        'settled_amount_minor' => -2_155_13,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'unnamed-period',
        'counterparty_name' => 'Merchant',
        'normalization_version' => 1,
        'description' => 'unnamed period row',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    $user = unnamedPeriodUser();
    unnamedPeriodLedger($user);
    $this->actingAs($user);
});

it('refuses a period this build cannot name instead of drawing one it was not asked for', function (): void {
    $page = unnamedPeriodBuilder('since_the_dawn_of_time');

    expect($page->text())->toContain(Lang::get('reports::builder.period.error.unknown_preset'))
        ->and($page->count('table'))->toBe(0);
});

// The positive control for the assertion above: a period the rail CAN produce
// still draws its report and says nothing about a refusal.
it('still draws a report for a period it does name', function (): void {
    $page = unnamedPeriodBuilder(ReportPeriodPreset::Last3Months->value);

    expect($page->text())->not->toContain(Lang::get('reports::builder.period.error.unknown_preset'))
        ->and($page->count('table'))->toBe(1);
});

it('refuses the builder own export over a period it cannot name', function (): void {
    Livewire::withQueryParams(['period' => 'since_the_dawn_of_time'])
        ->test(ReportBuilder::class)
        ->call('export')
        ->assertOk()
        ->assertSet('flashMessage', Lang::get('reports::builder.period.error.unknown_preset'));
});

it('refuses the export route over a period it cannot name', function (): void {
    $response = $this->get('/reports/export?period=since_the_dawn_of_time');

    expect($response->status())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->and($response->getContent())->toContain(Lang::get('reports::builder.period.error.unknown_preset'));
});

// A stored definition is a different boundary and keeps its coercion: that word
// was written by a peer on a newer build, and refusing it would take the saved
// report — and the dashboard, if it is pinned — down with it.
it('still opens a saved report whose stored period this build cannot name', function (): void {
    $user = unnamedPeriodUser();
    $this->actingAs($user);

    $id = app(DatabaseManager::class)->connection()->table('saved_reports')->insertGetId([
        'user_id' => $user->id,
        'name' => 'From a peer on another build',
        'definition' => '{"metric":"spend","dimension":"category","periodPreset":"since_the_dawn_of_time","granularity":"monthly","currencyMode":"base","viz":"table"}',
        'pinned' => false,
        'pin_order' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ReportBuilder::class, ['report' => $id])
        ->assertOk()
        ->assertDontSee(Lang::get('reports::builder.period.error.unknown_preset'));
});

// The refusal wears the app's own refusal furniture rather than its empty
// state, and carries the role that announces it.
it('draws the refusal as a refusal', function (): void {
    $refusal = unnamedPeriodBuilder('since_the_dawn_of_time')->firstOrFail('[role=alert]');

    expect($refusal->attribute('class'))->toContain('rose')
        ->and($refusal->attribute('aria-live'))->toBeNull()
        ->and($refusal->attribute('id'))->toBe('report-period-error');
});

it('never draws the refusal in the empty state it is not', function (): void {
    $page = unnamedPeriodBuilder('since_the_dawn_of_time');

    expect($page->count('.srch-no-results'))->toBe(0);
});

// The control the refusal is about says so itself, so a reader arriving at it
// by keyboard is told why it is still showing the value that was refused.
it('points the control at the refusal that named it', function (): void {
    $page = RenderedMarkup::of(
        Livewire::test(ReportBuilder::class)
            ->set('periodPreset', ReportPeriodPreset::Custom->value)
            ->set('customFrom', '2027-02-29')
            ->set('customTo', '2027-03-31')
            ->html(),
    );

    expect($page->text())->toContain(Lang::get('reports::builder.period.error.malformed'))
        ->and($page->count('#report-custom-from[aria-invalid=true][aria-describedby=report-period-error]'))->toBe(1)
        ->and($page->count('#report-custom-to[aria-invalid=true][aria-describedby=report-period-error]'))->toBe(1);
});

// And stops saying so once there is nothing to say, or every field on the page
// reads as permanently invalid.
it('leaves the control unmarked when nothing was refused', function (): void {
    $page = RenderedMarkup::of(
        Livewire::test(ReportBuilder::class)
            ->set('periodPreset', ReportPeriodPreset::Custom->value)
            ->set('customFrom', '2026-09-01')
            ->set('customTo', '2026-09-30')
            ->html(),
    );

    expect($page->count('[aria-invalid=true]'))->toBe(0)
        ->and($page->count('[role=alert]'))->toBe(0);
});
