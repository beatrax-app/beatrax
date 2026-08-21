<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobileWelcomeScreen;

uses(RefreshDatabase::class);

// The phone is the companion, not the place a household sets Beatrax up, so
// importing from another device leads and creating an account is offered second.
// It still has to be possible, which is why that second option is a button rather
// than a footnote.

it('offers importing before creating an account', function (): void {
    $html = (string) Livewire::test(MobileWelcomeScreen::class)->html();

    $import = strpos($html, Lang::get('mobile::welcome.import'));
    $create = strpos($html, Lang::get('mobile::welcome.create_account'));

    expect($import)->not->toBeFalse()
        ->and($create)->not->toBeFalse()
        ->and($import)->toBeLessThan($create, 'create-account is offered before importing');
});

it('gives importing the dominant treatment', function (): void {
    // Asserted against the RENDERED page rather than the blade: the filled
    // treatment moved into x-core::primary-button, so the class no longer
    // appears in the source at all — while what a reader sees is unchanged.
    $html = Livewire::test(MobileWelcomeScreen::class)->html();

    $filled = strpos($html, 'bg-emerald-600');
    $import = strpos($html, Lang::get('mobile::welcome.import'));
    $create = strpos($html, Lang::get('mobile::welcome.create_account'));

    expect($filled)->not->toBeFalse('nothing on the welcome screen carries the filled treatment')
        ->and($import)->not->toBeFalse()
        ->and($create)->not->toBeFalse()
        ->and(abs($filled - $import))->toBeLessThan(abs($filled - $create), 'the filled button belongs to create-account');
});

it('says the phone can still stand alone', function (): void {
    $note = Lang::get('mobile::welcome.create_account_note');

    expect((string) Livewire::test(MobileWelcomeScreen::class)->html())->toContain($note);

    // Recommending the desktop must not read as a refusal — a reader who wants
    // everything on their phone is supported, and the copy has to say so.
    expect($note)->not->toBe('mobile::welcome.create_account_note');
});

it('carries the recommendation in every shipped locale', function (): void {
    $missing = [];

    foreach (glob(base_path('Modules/Mobile/Resources/lang/*/welcome.php')) as $file) {
        /** @var array<string, string> $messages */
        $messages = require $file;

        if (! isset($messages['create_account_note']) || trim($messages['create_account_note']) === '') {
            $missing[] = basename(dirname($file));
        }
    }

    expect($missing)->toBe([], 'no recommendation for: '.implode(', ', $missing));
});
