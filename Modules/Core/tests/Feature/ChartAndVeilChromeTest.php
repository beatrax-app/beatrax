<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Modules\Core\Models\User;

/*
 * Three things the shell has to hand the client, all of which were missing.
 *
 * `data-base-currency` is read by beatraxLocaliseChart to format the money
 * axis and was never rendered, so every chart fell back to EUR — a GBP user
 * saw pounds on the page and euros on the axis beside them.
 *
 * `data-chart-labels` replaces the accessible name ApexCharts writes onto its
 * own <svg> ("donut chart with 14 data series"), which is English whatever
 * the page language is.
 *
 * `data-locked-label` names the privacy veil once lock.js raises it to
 * role="dialog". The name used to be a literal in the script.
 */

beforeEach(function (): void {
    $this->chromeUser = User::query()->create([
        'username' => 'chrome-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'locale' => 'nl',
    ]);

    test()->actingAs($this->chromeUser);
});

it('renders the base currency the chart axis formats with', function (): void {
    $html = $this->followingRedirects()->get('/')->getContent();

    expect($html)->toContain('data-base-currency="'.config('currency.base', 'EUR').'"');
});

it('hands the client a localised name for every chart type', function (): void {
    App::setLocale('nl');

    $html = $this->followingRedirects()->get('/')->getContent();

    expect($html)->toContain('data-chart-labels=');

    preg_match('/data-chart-labels="([^"]*)"/', (string) $html, $m);
    expect($m)->toHaveCount(2);

    $labels = json_decode(html_entity_decode($m[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    expect($labels)->toHaveKeys(['donut', 'bar', 'line', 'rangeArea'])
        ->and($labels['donut'])->toBe('Ringdiagram')
        ->and($labels['bar'])->toBe('Staafdiagram');
});

it('names the privacy veil in the interface language, not in the script', function (): void {
    App::setLocale('nl');

    $html = $this->followingRedirects()->get('/')->getContent();

    expect($html)->toContain('data-locked-label="App vergrendeld"');
});

it('has no English lock label left in the script', function (): void {
    $lock = file_get_contents(base_path('resources/js/lock.js'));

    expect($lock)->toBeString()
        ->and($lock)->not->toContain("'App locked'")
        ->and($lock)->toContain('dataset.lockedLabel');
});

it('uses one Dutch word for signing out on both lock screens', function (): void {
    $auth = require base_path('Modules/Auth/Resources/lang/nl/lock_screen.php');
    $mobile = require base_path('Modules/Mobile/Resources/lang/nl/lock.php');

    // The desktop lock said "Afmelden" and the phone lock "Uitloggen" for
    // the same action on two otherwise identical screens.
    expect($mobile['sign_out'])->toBe($auth['sign_out']);
});

it('does not disagree with itself in any other locale either', function (): void {
    $mismatched = [];
    foreach (glob(base_path('Modules/Auth/Resources/lang/*/lock_screen.php')) ?: [] as $file) {
        $locale = basename(dirname($file));
        $mobileFile = base_path('Modules/Mobile/Resources/lang/'.$locale.'/lock.php');
        if (! is_file($mobileFile)) {
            continue;
        }
        $auth = require $file;
        $mobile = require $mobileFile;
        if (($auth['sign_out'] ?? null) !== ($mobile['sign_out'] ?? null)) {
            $mismatched[] = $locale.': '.($auth['sign_out'] ?? '?').' vs '.($mobile['sign_out'] ?? '?');
        }
    }

    expect($mismatched)->toBe([]);
});
