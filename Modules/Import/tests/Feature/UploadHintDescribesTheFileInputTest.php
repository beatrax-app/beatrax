<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\UploadWizard;

// The sr-only format list sat in the page header behind an id nothing pointed
// at, worded as a failure and byte-identical to the real validation error —
// so a screen reader met it on entry and could not tell hint from rejection.

beforeEach(function (): void {
    $this->hintUser = User::query()->create([
        'username' => 'hint-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'locale' => 'nl',
    ]);

    test()->actingAs($this->hintUser);
});

it('points the file input at the format hint', function (): void {
    $html = Livewire::test(UploadWizard::class)->html();

    expect($html)->toContain('id="upload-statement-mime-hint"')
        ->and($html)->toContain('aria-describedby="upload-statement-mime-hint"');
});

it('words the hint as a hint, not as a rejection', function (): void {
    $hint = require base_path('Modules/Import/Resources/lang/nl/upload.php');
    $en = require base_path('Modules/Import/Resources/lang/en/upload.php');

    expect($hint['mime_hint'])->not->toBe($hint['errors']['file_extensions'])
        ->and($en['mime_hint'])->not->toBe($en['errors']['file_extensions']);
});

it('keeps hint and error distinct in every shipped locale', function (): void {
    $same = [];
    foreach (glob(base_path('Modules/Import/Resources/lang/*/upload.php')) ?: [] as $file) {
        $lines = require $file;
        if (($lines['mime_hint'] ?? null) === ($lines['errors']['file_extensions'] ?? null)) {
            $same[] = basename(dirname($file));
        }
    }

    expect($same)->toBe([]);
});
