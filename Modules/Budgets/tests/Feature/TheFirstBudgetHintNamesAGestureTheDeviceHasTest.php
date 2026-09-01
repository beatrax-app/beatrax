<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

// "Click into a cell below" is the first instruction an empty budget gives, and
// on the phone it names a gesture the device does not have — the same defect the
// drop-zone already answers by swapping its copy on a mobile runtime. Found on
// an iPhone reading "Klik hieronder in een cel".

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'gesture-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'gesture-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

it('asks a phone to tap rather than to click', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::test(BudgetsPage::class)
        ->assertOk()
        ->assertSee('Tap a cell below to start assigning your first month.')
        ->assertDontSee('Click into a cell below');
});

it('still asks a desktop to click, so the phone copy has not replaced both', function (): void {
    Livewire::test(BudgetsPage::class)
        ->assertOk()
        ->assertSee('Click into a cell below to start assigning your first month.')
        ->assertDontSee('Tap a cell below');
});

it('gives every locale a touch line that is not simply its click line', function (): void {
    $locales = glob(base_path('Modules/Budgets/Resources/lang/*'), GLOB_ONLYDIR) ?: [];

    // A walk that found nothing would pass while proving nothing.
    expect(count($locales))->toBeGreaterThan(20);

    $unchanged = [];

    foreach ($locales as $dir) {
        /** @var array<string, mixed> $messages */
        $messages = require $dir.'/messages.php';
        $empty = $messages['empty'] ?? [];

        foreach (['first_hint', 'copy_hint'] as $key) {
            $click = $empty[$key] ?? null;
            $touch = $empty[$key.'_touch'] ?? null;

            if (! is_string($click) || ! is_string($touch)) {
                $unchanged[] = basename($dir).' is missing '.$key.'_touch';

                continue;
            }

            if ($click === $touch) {
                $unchanged[] = basename($dir).' · '.$key.'_touch still reads "'.$touch.'"';
            }
        }
    }

    expect($unchanged)->toBe([], implode("\n  ", [
        'A touch line that equals its click line names the gesture the phone does not have:',
        ...$unchanged,
    ]));
});
