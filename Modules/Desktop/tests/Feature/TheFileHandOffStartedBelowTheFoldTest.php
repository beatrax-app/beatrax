<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;

// The staging screen renders inside layouts.app, whose shell is already
// min-h-screen. The view opened a second viewport-height block and centred the
// card inside it, so the one button the page exists for — Start import, after
// the operating system handed Beatrax a file — began a full screen below the
// fold, under whatever system alerts happened to be showing.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'staging-fold',
        'password' => 'opensesame-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('opens no second viewport-height block inside the app shell', function (): void {
    $view = (string) file_get_contents(base_path('Modules/Desktop/Resources/views/staging.blade.php'));

    expect(PatternScan::matches('/class="[^"]*min-h-screen/', $view))->toBeFalse();
});

it('still reaches the page it stages for', function (): void {
    $this->get(route('desktop.file-staging'))
        ->assertOk()
        ->assertSee('Open Imports');
});
