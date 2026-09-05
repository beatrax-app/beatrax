<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

uses(RefreshDatabase::class);

// The corpus seeds the filing country's own wording into the column —
// "Zorgkosten", not "Healthcare costs" — and that column is unchanged. What
// changed is the reading of it: the names now follow the reader, and the note
// beside them says the return is the thing that keeps the country's words.

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

// The stored column is still the jurisdiction's, which is what the corpus_key
// resolution reads past. Nothing here asserts what a screen shows.
it('still seeds the Dutch corpus into the column in Dutch', function (): void {
    $user = wordingNoteUser('wording-seeded', 'nl');

    $names = DB::table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->pluck('name')
        ->all();

    expect($names)->toContain('Zorgkosten');
});

// The note used to say the list keeps the country's words in every app
// language. It says the opposite now, because the list does.
it('names the country whose return keeps its own wording', function (): void {
    $user = wordingNoteUser('wording-note-en', 'nl');
    $this->actingAs($user);

    Livewire::test(SettingsPage::class)
        ->assertSeeHtml('data-testid="settings-country-wording-note"')
        ->assertSee('Tax category names are shown in your language; the Netherlands tax return itself uses its own wording.');
});

// Nothing is seeded without a country, so there is no foreign wording to
// explain and no sentence to read.
it('says nothing about wording when no country is chosen', function (): void {
    $user = wordingNoteUser('wording-note-none', '');
    $this->actingAs($user);

    Livewire::test(SettingsPage::class)
        ->assertDontSeeHtml('data-testid="settings-country-wording-note"');
});

// The note follows the reader, and so now do the words it describes.
it('reads in the reader’s own language, naming the country in that language', function (): void {
    $user = wordingNoteUser('wording-note-nl', 'de');
    DB::table('users')->where('id', $user->id)->update(['locale' => 'nl']);

    $this->actingAs($user->fresh())
        ->get(route('settings'))
        ->assertOk()
        ->assertSee('op de belastingaangifte van Duitsland staan ze in de eigen woorden van dat land', false)
        ->assertDontSee(':country');
});
