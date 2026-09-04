<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Models\Goal;

// Measured at 390px: "Αποθεματικό έκτακτης ανάγκης" needed 226px and had 150,
// so it ended in an ellipsis while the target date under it wrapped freely
// across the same column. The percentage and three actions beside it took the
// other 200px of the row and none of them would give any back.
function goalPhoneRowMarkup(string $html): string
{
    $list = strpos($html, 'goals-phone-list');
    expect($list)->not->toBeFalse('The page rendered no phone list.');

    $open = strpos($html, '<li', $list);
    expect($open)->not->toBeFalse();

    $close = strpos($html, '</li>', $open);
    expect($close)->not->toBeFalse();

    return substr($html, $open, $close - $open);
}

/** @return array{name: string, block: string} the name's own class list and that of the block it sits in */
function goalPhoneNameClassLists(string $row): array
{
    $block = PatternScan::first('/<div\b[^>]*\bclass="([^"]*)"/', $row);
    $name = PatternScan::first('/<p\b[^>]*\bclass="([^"]*primary[^"]*)"/', $row);

    return ['name' => $name[1] ?? '', 'block' => $block[1] ?? ''];
}

// The rule the row leans on to put the percentage and the actions on a line of
// their own. It lives inside @layer components, so the unlayered reader cannot
// see it and the source is read directly.
function goalPhoneRowWrapsInStylesheet(): bool
{
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    return preg_match(
        '/@media \(max-width: 767px\) \{.*?\.card-list-item \{[^}]*flex-wrap:\s*wrap/s',
        $css,
    ) === 1;
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'goal-name-column', 'password' => 'fixture-password-12chars', 'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    // 28 characters, the longest of the four names the demo ships in Greek.
    Goal::create([
        'user_id' => $this->user->id,
        'name' => 'Αποθεματικό έκτακτης ανάγκης',
        'target_minor' => 500000,
        'currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addMonthsNoOverflow(18)->toDateString(),
        'status' => 'active',
    ]);
});

it('gives the goal name a line of its own rather than the remainder of the row', function (): void {
    $classes = goalPhoneNameClassLists(goalPhoneRowMarkup(Livewire::test(GoalsPage::class)->html()));

    expect(str_contains($classes['block'], 'w-full'))->toBeTrue(
        'The block holding the goal name shares the row with the percentage and the three actions. Those '
        ."take 200px of a 390px phone, and a zero-basis block never widens the row it is in.\n  "
        .$classes['block'],
    );

    expect(goalPhoneRowWrapsInStylesheet())->toBeTrue(
        'No max-width:767px rule wraps .card-list-item, so a full-width name block pushes the percentage '
        .'and the actions off the right edge instead of onto the next line.',
    );
});

it('lets a long goal name wrap rather than cutting it at the ellipsis', function (): void {
    $classes = goalPhoneNameClassLists(goalPhoneRowMarkup(Livewire::test(GoalsPage::class)->html()));

    expect(str_contains($classes['name'], 'truncate'))->toBeFalse(
        'The goal name is truncated to one line. `truncate` sets white-space:nowrap from the utilities '
        .'layer, which beats the two-line clamp .card-list-item .primary declares in the components layer, '
        ."so the name loses its tail where the row has a second line to give it.\n  ".$classes['name'],
    );

    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect(PatternScan::matches('/\.card-list-item \.primary \{[^}]*line-clamp:\s*2/s', $css))->toBeTrue(
        'Nothing clamps the goal name, so a name with no end wraps down the page instead of taking two '
        .'lines and stopping.',
    );
});
