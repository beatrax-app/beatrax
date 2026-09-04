<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\PatternScan;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

uses(RefreshDatabase::class);

// The direction is read out of the shipped sentence and then executed, so a
// rewrite that keeps the meaning keeps these tests and one that flips it fails.
// Asserting the sentence equals a literal would only re-state the copy, and the
// copy was what was wrong.

/** @return string the switch direction the help's advice names, or '' when it names none */
function hideDetailsAdviceDirection(): string
{
    $help = Lang::get('notifications::settings.hide_details.help');

    return preg_match('/\bturn\s+(?:it\s+)?(on|off)\b/i', $help, $matches) === 1
        ? strtolower($matches[1])
        : '';
}

function hideDetailsHelpReader(): User
{
    return User::query()->create([
        'username' => 'hide-details-help-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('tells the reader which way to move the switch', function (): void {
    expect(hideDetailsAdviceDirection())->toBeIn(
        ['on', 'off'],
        'notifications::settings.hide_details.help no longer names a switch direction, so the two tests below cannot check that the direction it names is the private one. Privacy advice a reader cannot act on is not advice; keep an explicit "turn on"/"turn off" in the sentence.',
    );
});

it('leaves amounts and merchant names off the banner when the reader follows the help', function (): void {
    $user = hideDetailsHelpReader();

    Livewire::actingAs($user)->test(NotificationsSettingsSection::class)
        ->set('hideDetails', hideDetailsAdviceDirection() === 'on')
        ->call('save')
        ->assertSet('saveError', '')
        ->assertSet('saved', true);

    /** @var SuppressionEvaluator $evaluator */
    $evaluator = $this->app->make(SuppressionEvaluator::class);

    $decision = $evaluator->shouldDeliver(
        $user->id,
        NotificationTrigger::PaymentReminder,
        CarbonImmutable::parse('2026-07-18 12:00:00'),
    );

    expect($decision->hideDetails)->toBeTrue(
        'A reader whose screen others can see follows notifications::settings.hide_details.help and lands on the state that puts amounts and merchant names in the banner. The switch is bound to hideDetails, where on means hide, so the sentence has to send that reader to on.',
    );
});

it('renders the switch the help describes in the state the help advises', function (): void {
    $user = hideDetailsHelpReader();

    $html = Livewire::actingAs($user)->test(NotificationsSettingsSection::class)
        ->set('hideDetails', hideDetailsAdviceDirection() === 'on')
        ->html();

    $label = Lang::get('notifications::settings.hide_details.label');

    expect($html)->toContain(e(Lang::get('notifications::settings.hide_details.help')));

    $matches = PatternScan::first('/<button\b[^>]*aria-label="'.preg_quote(e($label), '/').'"[^>]*>/', $html);

    expect($matches)->not->toBe(
        [],
        'No switch in the rendered settings section carries the hide-details label as its accessible name, so nothing pins the help to the control it describes.',
    );

    expect(str_contains($matches[0], 'aria-checked="true"'))->toBeTrue(
        'The help sends the reader to a switch state, and that state renders the hide-details switch off. The label promises to hide, so the state the sentence advises has to be the checked one.',
    );
});
