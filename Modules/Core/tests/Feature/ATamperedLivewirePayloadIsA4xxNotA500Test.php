<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\AutoImportSettingsSection;
use Modules\Core\Public\Support\PatternScan;

// Both writes below are correctly REFUSED already. What was wrong is the shape
// of the refusal: a tampered client payload came back as a server fault, which
// is the one answer that says the server did something wrong.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'tampered-payload',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

function tamperedSnapshot(): string
{
    $html = Livewire::test(AutoImportSettingsSection::class)->html();

    $matches = PatternScan::first('/wire:snapshot="([^"]*)"/', $html);
    expect($matches)->toHaveCount(2);

    return html_entity_decode($matches[1], ENT_QUOTES);
}

/**
 * @param  array<string, mixed>  $updates
 */
function tamperedUpdate(array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => tamperedSnapshot(),
            'updates' => $updates,
            'calls' => [],
        ]],
    ]);
}

it('answers a write to a locked property with a 403 rather than a 500', function (): void {
    tamperedUpdate(['userId' => 999])->assertForbidden();
});

it('answers a write to a property the component does not have with a 400 rather than a 500', function (): void {
    tamperedUpdate(['openingInput' => '999.99'])->assertStatus(400);
});

// The locked-property exception renders itself -- a 419 with debug off, a full
// error page with it on -- so the answer used to depend on how the bundle was
// built. Mapping ahead of that is what makes it one answer.
it('answers with the same 403 whether or not debug is on', function (): void {
    config(['app.debug' => false]);

    tamperedUpdate(['userId' => 999])->assertForbidden();
});
