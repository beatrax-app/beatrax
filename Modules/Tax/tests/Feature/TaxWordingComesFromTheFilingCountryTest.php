<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

uses(RefreshDatabase::class);

// The tax corpus is the filing country's own wording — "Zorgkosten", not
// "Healthcare costs" — because it has to match the boxes on that country's
// return. It is the one thing the country brings in its own language, and the
// screen that lists it has to say so rather than deny it one card above.

function wordingNoteUser(string $username, string $country): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'opensesame-long-enough',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    if ($country !== '') {
        app(UserCountry::class)->store($user->id, $country);
    }

    return $user->fresh();
}

it('seeds the Dutch corpus in Dutch for an English reader', function (): void {
    $user = wordingNoteUser('wording-seeded', 'nl');

    $names = DB::table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->pluck('name')
        ->all();

    expect($names)->toContain('Zorgkosten');
});

// The claim the help line makes is about the app. The exception is named
// beside the words it is about, on the same screen and in the same card.
it('names the country whose wording the categories keep', function (): void {
    $user = wordingNoteUser('wording-note-en', 'nl');
    $this->actingAs($user);

    Livewire::test(SettingsPage::class)
        ->assertSeeHtml('data-testid="settings-country-wording-note"')
        ->assertSee('Tax category names come from the tax return used in Netherlands, so they stay in its own words in every app language.');
});

// Nothing is seeded without a country, so there is no foreign wording to
// explain and no sentence to read.
it('says nothing about wording when no country is chosen', function (): void {
    $user = wordingNoteUser('wording-note-none', '');
    $this->actingAs($user);

    Livewire::test(SettingsPage::class)
        ->assertDontSeeHtml('data-testid="settings-country-wording-note"');
});

// The note itself follows the reader even though the words it describes do not.
it('reads in the reader’s own language, naming the country in that language', function (): void {
    $user = wordingNoteUser('wording-note-nl', 'de');
    DB::table('users')->where('id', $user->id)->update(['locale' => 'nl']);

    $this->actingAs($user->fresh())
        ->get(route('settings'))
        ->assertOk()
        ->assertSee('Namen van belastingcategorieën komen uit de aangifte die in Duitsland wordt gebruikt', false)
        ->assertDontSee(':country');
});
