<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;
use Modules\Tax\Internal\Http\Livewire\TaxPage;

uses(RefreshDatabase::class);

// The country picker left the Tax section and now sits beside the language.
// The prompt that sends a reader to change it has to name and reach the
// control that still exists, in every language the prompt is written in.

function taxPromptUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'opensesame-long-enough',
        'is_developer' => false,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $user->setRelations([]);

    return $user;
}

function taxPromptAnchor(string $html): string
{
    expect(preg_match('/href="[^"]*settings[^"]*#([a-z-]+)"/', $html, $matches))->toBe(1);

    return $matches[1];
}

it('sends the reader to an anchor the settings page actually has', function (): void {
    $user = taxPromptUser('tax-prompt-anchor');
    $this->actingAs($user);

    $anchor = taxPromptAnchor(Livewire::test(TaxPage::class)->html());

    Livewire::test(SettingsPage::class)->assertSeeHtml('id="'.$anchor.'"');
});

it('names the country section rather than the tax section', function (): void {
    $user = taxPromptUser('tax-prompt-english');
    $this->actingAs($user);

    $html = Livewire::test(TaxPage::class)->html();

    expect($html)
        ->toContain('Settings → Country')
        ->not->toContain('Settings → Tax');

    expect(taxPromptAnchor($html))->toBe('country');
});

// The destination word is a parameter, so the sentence reads in the reader's
// language AND names the section as that language labels it.
it('names the section in the reader’s own language', function (): void {
    $user = taxPromptUser('tax-prompt-dutch');
    app(DatabaseManager::class)->connection()
        ->table('users')->where('id', $user->id)->update(['locale' => 'nl']);

    $this->actingAs($user->fresh())
        ->get(route('tax.index'))
        ->assertOk()
        ->assertSee('Instellingen → Land')
        ->assertDontSee(':section');
});
