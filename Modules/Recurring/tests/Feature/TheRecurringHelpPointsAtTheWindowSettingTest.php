<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Lang;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;
use Modules\Recurring\Public\Support\RecurringDetectionWindow;

uses(RefreshDatabase::class);

// The review queue answers "approve or reject?" and never "why is this here,
// and why is my yearly insurance not?". Both answers are the detection window,
// which lives on another screen entirely — so the help has to name that screen's
// own control rather than restate a number that can be changed.

/** @return list<string> every locale the app is offered in */
function recurringHelpLocales(): array
{
    return array_map(static fn (Locale $case): string => $case->value, Locale::cases());
}

it('names the setting by the label the settings page actually draws', function (): void {
    $silent = [];

    foreach (recurringHelpLocales() as $locale) {
        $help = require base_path('Modules/Recurring/Resources/lang/'.$locale.'/help.php');
        $settings = require base_path('Modules/Core/Resources/lang/'.$locale.'/settings.php');

        $label = (string) $settings['recurring']['window_label'];
        if (! str_contains(str_replace(':setting', $label, (string) $help['review']), $label)) {
            $silent[] = $locale;
        }
    }

    expect($silent)->toBe([], implode("\n", [
        'These locales point at a setting without using the words on it:',
        ...$silent,
        '',
        'The sentence carries :setting so it always spells the control the way',
        'the settings page does. A locale that paraphrases sends the reader',
        'looking for a label that is not there.',
    ]));
});

it('translates the sentence rather than shipping the English one', function (): void {
    $english = (require base_path('Modules/Recurring/Resources/lang/en/help.php'))['review'];

    $untranslated = [];
    foreach (array_diff(recurringHelpLocales(), ['en']) as $locale) {
        $file = base_path('Modules/Recurring/Resources/lang/'.$locale.'/help.php');
        if (! is_file($file)) {
            $untranslated[] = $locale.' (no file)';

            continue;
        }
        if (((require $file)['review'] ?? '') === $english) {
            $untranslated[] = $locale;
        }
    }

    expect($untranslated)->toBe([]);
});

// "the shortest span it can work with" is the claim, and it is only true while
// the shipped default and the floor the settings form enforces are the same
// number. Moving one without the other makes the sentence a guess.
it('is describing a default that really is the floor', function (): void {
    $settingsPage = (string) file_get_contents(
        base_path('Modules/Shell/Internal/Http/Livewire/SettingsPage.php')
    );

    $at = strpos($settingsPage, 'recurringDetectionWindowMonths');
    expect($at)->not->toBeFalse('The settings page no longer carries a detection-window field.');

    $rule = substr($settingsPage, max(0, (int) $at - 400), 400);
    expect(str_contains($rule, "min:'.RecurringDetectionWindow::MINIMUM_MONTHS"))->toBeTrue(
        'The detection-window field spells a floor of its own instead of the one the detectors fall back to.',
    );

    expect((new User)->recurring_detection_window_months)->toBe(
        RecurringDetectionWindow::MINIMUM_MONTHS,
        'The help tells the reader the window starts at the shortest span the detector can work with. It no longer does.',
    );
});

it('draws the tip beside the heading, with the setting named inside it', function (): void {
    $user = User::create([
        'username' => 'recurring-help-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $html = Livewire::test(RecurringReviewPage::class)->html();

    expect($html)->toContain('id="help-tip-recurring-review"')
        ->and($html)->toContain('popovertarget="help-tip-recurring-review"')
        ->and($html)->toContain(e(Lang::get('core::settings.recurring.window_label')));
});
