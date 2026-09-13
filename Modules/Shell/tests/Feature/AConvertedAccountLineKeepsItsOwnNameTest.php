<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\FX\Public\Support\BundledRates;
use Modules\Shell\Internal\Http\Livewire\NetWorthCard;

// Measured at 390x844 on the expanded breakdown: the two converted lines
// rendered their name span 0px wide and their figure to 569px and 580px, past
// a 390px viewport, while document.body.scrollWidth still answered 390 because
// an ancestor clipped it. The unconverted lines on the same list were fine.
//
// The cause is not the account name. A disclosure spells out a date, its age
// and its source, so the box holding it grows with the sentence — and it was
// growing inside the one box on the row declared shrink-0, so every pixel it
// took came off the only flexible thing there, which was the name.

if (! function_exists('convertedLineAccount')) {
    function convertedLineAccount(DatabaseManager $db, int $userId, string $name, int $openingMinor, string $currency): void
    {
        $db->connection()->table('accounts')->insert([
            'user_id' => $userId,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(3)),
            'kind' => 'bank',
            'iban' => 'NL00CONV'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'default_currency' => $currency,
            'opening_balance_minor' => $openingMinor,
            'opening_balance_as_of_date' => '2026-01-01',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-13 12:00:00'));

    $this->db = app(DatabaseManager::class);
    $this->user = User::create([
        'username' => 'converted-line-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    convertedLineAccount($this->db, $this->user->id, 'ASN Bank', 288_206, 'EUR');
    convertedLineAccount($this->db, $this->user->id, 'Japan Trip Card', 11_260_000, 'JPY');

    $this->db->connection()->table('exchange_rates')->updateOrInsert(
        ['base_currency' => 'EUR', 'quote_currency' => 'JPY', 'rate_date' => '2026-06-05', 'source' => BundledRates::SOURCE],
        ['rate' => '159.10', 'created_at' => now(), 'updated_at' => now()],
    );

    $this->breakdown = RenderedMarkup::of(
        Livewire::test(NetWorthCard::class)->call('toggle')->html(),
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The positive control. Every question below is about a disclosure inside the
// breakdown, and a list that drew none of them would answer all of them "clean"
// while saying nothing at all.
it('draws a converted line carrying a disclosure', function (): void {
    expect($this->breakdown->count('ul li'))->toBeGreaterThan(1)
        ->and($this->breakdown->count('[data-fx-disclosure]'))->toBeGreaterThan(0);
});

it('keeps a converted account line naming its own account', function (): void {
    $named = array_values(array_filter(
        $this->breakdown->all('ul li'),
        static fn (RenderedMarkup $line): bool => str_contains($line->text(), 'Japan Trip Card'),
    ));

    expect($named)->toHaveCount(1)
        ->and($named[0]->text())->toContain('rates as of');
});

// The shape, not the instance: a sentence of unbounded length inside a box
// that refuses to shrink takes its width from whatever on the row can give.
it('never puts a disclosure inside a box that refuses to shrink', function (): void {
    expect($this->breakdown->count('.shrink-0 [data-fx-disclosure]'))->toBe(0);
});

// A figure is the one thing on this row that still may not shrink: a formatted
// amount carries no break opportunity, so shrinking its box overflows it
// visibly rather than wrapping it.
it('keeps the figure itself unbreakable', function (): void {
    expect($this->breakdown->count('.shrink-0.whitespace-nowrap'))->toBeGreaterThan(0);
});
