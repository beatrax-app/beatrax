<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Dto\EnvelopeRow;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\TopCategoryRow;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\TopCategoriesByPeriodQuery;

// Both screens answer "what did this envelope cost this month, in my money",
// and both start from the same stored euro figures. The dashboard card converts
// each currency once and spreads the result over the categories; the grid used
// to convert each category on its own, which rounds a second time.

function gdcJpyReaderWithEuroSpending(): array
{
    $user = User::create([
        'username' => 'grid-card-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'JPY',
    ]);

    DB::table('users')->where('id', $user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $suffix = bin2hex(random_bytes(4));

    $accountId = (int) DB::table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Euro account '.$suffix,
        'slug' => 'gdc-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00GDC'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $runId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/gdc-'.$suffix.'.xml',
        'sha256' => hash('sha256', 'gdc-'.$suffix),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'committed',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $categories = [];
    // Two figures whose euro-to-yen conversions each round up while their sum
    // rounds down, so converting them apart and converting them together are a
    // whole minor unit apart. Nothing about the pair is special to yen.
    foreach ([['Small', 101], ['Large', 201]] as $index => [$name, $minor]) {
        $category = Category::create([
            'user_id' => null,
            'name' => $name.' '.$suffix,
            'slug' => 'gdc-'.$index.'-'.$suffix,
            'kind' => 'expense',
            'display_order' => $index + 1,
        ]);

        $categories[$name] = (int) $category->id;

        DB::table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'type' => 'expense',
            'posted_at' => CarbonImmutable::now()->startOfMonth()->addDay()->toDateString(),
            'booked_at' => CarbonImmutable::now()->startOfMonth()->addDay()->toDateTimeString(),
            'value_date' => CarbonImmutable::now()->startOfMonth()->addDay()->toDateString(),
            'amount_minor' => -$minor,
            'currency' => 'EUR',
            'settled_amount_minor' => -$minor,
            'settled_currency' => 'EUR',
            'category_id' => $categories[$name],
            'counterparty_normalized' => 'gdc vendor '.$index,
            'normalization_version' => 1,
            'source_format' => 'camt053',
            'source_row_index' => $index + 1,
            'fingerprint' => hash('sha256', 'gdc-'.$suffix.'-'.$index),
            'fingerprint_version' => 3,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    return [$user, $categories];
}

it('prices every envelope the same on the budgets grid and on the dashboard card', function (): void {
    [$user, $categories] = gdcJpyReaderWithEuroSpending();
    $this->actingAs($user);

    $period = app(PeriodQuery::class)->current();

    $grid = [];
    foreach (app(CarryoverQuery::class)->forUserAndPeriod($user, $period)['rows'] as $categoryId => $row) {
        /** @var EnvelopeRow $row */
        $grid[$categoryId] = $row->spentMinor;
    }

    $card = [];
    foreach (app(TopCategoriesByPeriodQuery::class)->for($user, $period)->rows as $row) {
        /** @var TopCategoryRow $row */
        $card[$row->categoryId] = $row->spend->toMinor();
    }

    // Both screens have to be answering about these euro rows at all, or the
    // comparison below is two absences agreeing with each other.
    expect(array_intersect_key($card, $categories === [] ? [] : array_flip(array_values($categories))))
        ->toHaveCount(2, 'the dashboard card did not price the two euro envelopes');

    foreach ($categories as $name => $categoryId) {
        expect($grid[$categoryId] ?? null)->toBe(
            $card[$categoryId] ?? null,
            sprintf('%s reads %s on the grid and %s on the card', $name, var_export($grid[$categoryId] ?? null, true), var_export($card[$categoryId] ?? null, true)),
        );
    }
});
