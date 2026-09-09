<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

// The empty state was three keys with the command name appended after the
// last of them, so every language had to end the sentence on the command.
// Dutch puts the verb after the object, and the screen read
// "om aan te roepen beatrax:doctor" — the words in the order English needs.

function emptyDoctorReader(string $username, string $locale): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
        'locale' => $locale,
    ]);
}

it('puts the command where Dutch puts it, not where English does', function (): void {
    $reader = emptyDoctorReader('doctor-empty-nl', 'nl');

    App::setLocale('nl');

    $response = $this->actingAs($reader)->get('/dev/doctor');

    $response->assertOk();

    $html = (string) $response->getContent();

    expect($html)->toContain('om <code class="font-mono">beatrax:doctor</code> aan te roepen.')
        ->and($html)->not->toContain('om aan te roepen');
});

// The bold word in the sentence and the label on the button are one key, so a
// screen cannot tell the reader to press something the button does not say.
it('names the button by the label the button carries', function (): void {
    $reader = emptyDoctorReader('doctor-empty-button', 'nl');

    App::setLocale('nl');

    $response = $this->actingAs($reader)->get('/dev/doctor');

    $response->assertOk();

    $label = Lang::get('dev::doctor.rerun');

    expect((string) $response->getContent())
        ->toContain('<span class="font-semibold">'.$label.'</span>');
});
