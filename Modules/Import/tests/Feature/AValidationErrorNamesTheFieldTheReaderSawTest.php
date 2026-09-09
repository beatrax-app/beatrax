<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Tests\Helpers\UploadIsolation;

// Measured on a Galaxy A51 set to Dutch: submitting the import form with no
// file answered "File is verplicht." The sentence was translated and the field
// in it was not, because the framework names a field from its property when
// nothing tells it otherwise — and the reader had just chosen that field under
// a label reading "Bestand".

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('names the field by the label the reader saw, in the locale they are reading', function (): void {
    app()->setLocale('nl');

    $component = Livewire::test(UploadWizard::class)->call('submit');

    $message = $component->errors()->first('file');

    expect($message)->toContain('Bestand')
        ->and($message)->not->toContain('File');
});

// Every locale, because the label is translated in all of them and a property
// name is translated in none.
it('never falls back to the property name in any published locale', function (string $locale): void {
    app()->setLocale($locale);

    $message = (string) Livewire::test(UploadWizard::class)->call('submit')->errors()->first('file');

    expect($message)->not->toBe('')
        ->and($message)->not->toContain('file field');
})->with(['nl', 'de', 'fr', 'es', 'pl']);
