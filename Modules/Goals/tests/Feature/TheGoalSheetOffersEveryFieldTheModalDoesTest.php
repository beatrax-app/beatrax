<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Ledger\Models\Account;
use Modules\Pots\Models\Pot;

// Found on an iPhone: the goal sheet a phone gets offered name, target amount
// and target date. Linking a goal to a pot was in the desktop modal only, so on
// a phone the feature did not exist. The blade already carries a comment about
// the same shape happening to the target-date field.
function goalFormFields(string $blade, string $marker, string $end): array
{
    $from = strpos($blade, $marker);
    expect($from)->not->toBeFalse();
    $to = strpos($blade, $end, $from);
    expect($to)->not->toBeFalse();

    $m = PatternScan::all('/wire:model(?:\.[a-z]+)*="([a-zA-Z0-9_.]+)"/', substr($blade, $from, $to - $from));

    return array_values(array_unique($m[1]));
}

it('binds the same fields in the phone sheet as in the desktop modal', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Goals/Resources/views/livewire/goals-page.blade.php'),
    );

    $sheet = goalFormFields($blade, '<x-core::bottom-sheet name="goal-form"', '</x-core::bottom-sheet>');
    $modal = goalFormFields($blade, 'field-id="goal-name"', 'Modal footer');

    sort($sheet);
    sort($modal);

    expect($sheet)->toBe($modal, 'The phone sheet and the desktop modal are the same form. A field bound in '
        .'one and not the other is a feature that exists on one screen size only, which is how linking a goal '
        ."to a pot came to be desktop-only.\n  sheet: ".implode(', ', $sheet)."\n  modal: ".implode(', ', $modal));
});

it('renders the linked-pot picker in the sheet with the pots the reader has', function (): void {
    $user = User::create(['username' => 'sheetpot', 'password' => 'opensesame', 'period_start_day' => 1]);
    $account = Account::create([
        'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'asn-sheetpot',
        'kind' => 'bank', 'iban' => 'NL57ASNB0900000001', 'default_currency' => 'EUR',
    ]);
    Pot::create([
        'user_id' => $user->id, 'account_id' => $account->id,
        'name' => 'Vakantie', 'currency' => 'EUR', 'status' => 'active',
    ]);

    Livewire::actingAs($user)->test(GoalsPage::class)
        ->assertSeeHtml('goal-pot-sheet')
        ->assertSee('Vakantie');
});
