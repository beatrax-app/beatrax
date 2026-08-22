<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;

// A card's sparkline is scaled against its own tallest bar. That maximum is a
// property of the row, not of the bar being drawn, and deriving it inside the
// bar loop walked the twelve-month series twelve times over per card.

$sscTemplate = static fn (): string => (string) file_get_contents(
    base_path('Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php'),
);

// How many times one card evaluates the scale: once per bar when the
// derivation sits inside the bar loop, once per card when it sits outside it.
$sscDerivationsPerCard = static function (string $template, int $bars): int {
    $lines = explode("\n", $template);

    $depth = 0;
    $inBarLoop = false;
    $inside = 0;
    $outside = 0;

    foreach ($lines as $line) {
        $opensBarLoop = str_contains($line, '@foreach ($row->sparkline as');

        if (str_contains($line, "max(array_map('abs'")) {
            $inBarLoop ? $inside++ : $outside++;
        }

        if (str_contains($line, '@foreach')) {
            $depth++;
            if ($opensBarLoop) {
                $inBarLoop = true;
            }
        }

        if (str_contains($line, '@endforeach')) {
            $depth--;
            if ($inBarLoop && $depth === 0) {
                $inBarLoop = false;
            }
        }
    }

    return ($inside * $bars) + $outside;
};

$sscUser = static function (string $username): User {
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
};

$sscLedger = static function (User $user, int $counterparties): void {
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'SSC ASN',
        'slug' => 'ssc-asn-'.$user->id,
        'kind' => 'bank',
        'iban' => 'NL00SSC'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ssc-'.$user->id.'.csv',
        'sha256' => hash('sha256', 'ssc-'.$user->id),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    for ($i = 0; $i < $counterparties; $i++) {
        $counterpartyId = DB::table('counterparties')->insertGetId([
            'user_id' => $user->id,
            'type' => 'merchant',
            'slug' => 'ssc-'.$i,
            'display_name' => 'Merchant '.$i,
            'merchant_name' => 'Merchant '.$i,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Three months apart and rising, so the twelve buckets carry different
        // heights and a wrong scale shows up as a wrong percentage.
        foreach ([1, 4, 7] as $offset) {
            $day = now()->subMonths($offset)->startOfMonth()->addDay();
            DB::table('transactions')->insert([
                'user_id' => $user->id,
                'account_id' => $accountId,
                'import_run_id' => $runId,
                'counterparty_id' => $counterpartyId,
                'fingerprint' => hash('sha256', 'ssc-'.$user->id.'-'.$i.'-'.$offset),
                'posted_at' => $day->toDateString(),
                'booked_at' => $day->toDateTimeString(),
                'value_date' => $day->toDateString(),
                'amount_minor' => -1000 * $offset,
                'currency' => 'EUR',
                'settled_amount_minor' => -1000 * $offset,
                'settled_currency' => 'EUR',
                'counterparty_normalized' => 'ssc-m'.$i,
                'counterparty_name' => 'Merchant '.$i,
                'normalization_version' => 1,
                'description' => 'ssc row '.$i.'-'.$offset,
                'type' => 'expense',
                'source_format' => 'asn-csv',
                'source_row_index' => $offset,
                'fingerprint_version' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};

it('derives a card s sparkline scale once, not once per bar', function () use ($sscTemplate, $sscDerivationsPerCard): void {
    expect($sscDerivationsPerCard($sscTemplate(), 12))->toBe(1);
});

it('draws every bar against the card s own tallest month', function () use ($sscUser, $sscLedger): void {
    $user = $sscUser('ssc-heights');
    $sscLedger($user, 1);

    $html = (string) Livewire::actingAs($user)->test(CounterpartyIndex::class)->html();

    preg_match_all('/class="bar ?[^"]*"\s+style="height: (\d+)%;"/', $html, $matches);

    // Buckets are oldest-first over twelve months, the last being the month
    // in progress: −70.00 at index 4, −40.00 at 7, −10.00 at 10, and the
    // tallest of the three is what the other two are drawn against.
    expect($matches[1])->toBe(['0', '0', '0', '0', '100', '0', '0', '57', '0', '0', '14', '0']);
});

it('marks the newest bar and no other', function () use ($sscUser, $sscLedger): void {
    $user = $sscUser('ssc-last');
    $sscLedger($user, 1);

    $html = (string) Livewire::actingAs($user)->test(CounterpartyIndex::class)->html();

    expect(substr_count($html, 'class="bar last"'))->toBe(1)
        ->and(substr_count($html, 'class="bar "'))->toBe(11);
});

it('keeps a card with no activity at all at zero rather than dividing by it', function () use ($sscUser): void {
    $user = $sscUser('ssc-silent');
    DB::table('counterparties')->insert([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'ssc-silent-one',
        'display_name' => 'Silent Merchant',
        'merchant_name' => 'Silent Merchant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $html = (string) Livewire::actingAs($user)->test(CounterpartyIndex::class)->html();

    preg_match_all('/class="bar ?[^"]*"\s+style="height: (\d+)%;"/', $html, $matches);

    expect($matches[1])->toBe(['0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0']);
});

it('scales each card against its own maximum, never against the grid s', function () use ($sscUser, $sscLedger): void {
    $user = $sscUser('ssc-per-card');
    $sscLedger($user, 1);

    $loud = DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'ssc-loud',
        'display_name' => 'Loud Merchant',
        'merchant_name' => 'Loud Merchant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $accountId = DB::table('accounts')->where('user_id', $user->id)->value('id');
    $runId = DB::table('import_runs')->where('user_id', $user->id)->value('id');
    $day = now()->subMonths(2)->startOfMonth()->addDay();
    DB::table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'counterparty_id' => $loud,
        'fingerprint' => hash('sha256', 'ssc-loud'),
        'posted_at' => $day->toDateString(),
        'booked_at' => $day->toDateTimeString(),
        'value_date' => $day->toDateString(),
        'amount_minor' => -900000,
        'currency' => 'EUR',
        'settled_amount_minor' => -900000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ssc-loud',
        'counterparty_name' => 'Loud Merchant',
        'normalization_version' => 1,
        'description' => 'ssc loud row',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $html = (string) Livewire::actingAs($user)->test(CounterpartyIndex::class)->html();

    // The quiet card still reaches 100% on its own tallest month even though
    // the loud one beside it is a hundred times larger.
    expect(substr_count($html, 'style="height: 100%;"'))->toBe(2);
});
