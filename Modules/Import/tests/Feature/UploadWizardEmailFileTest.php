<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Internal\Enums\ImportType;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('offers the two email formats under the email import type, per the UI-SPEC', function (): void {
    $component = Livewire::test(UploadWizard::class)->set('importType', ImportType::Email->value);
    /** @var UploadWizard $instance */
    $instance = $component->instance();

    $available = $instance->availableFormats();

    expect($available)->toBe([
        ['value' => 'eml', 'label' => 'Email message (.eml)'],
        ['value' => 'mbox', 'label' => 'Mailbox archive (.mbox)'],
    ]);
});

it('accepts an .eml upload under the email import type', function (): void {
    $emlBytes = file_get_contents(__DIR__.'/../../../Receipts/tests/fixtures/paypal/current-receipt.eml');
    $file = UploadedFile::fake()->createWithContent('paypal-receipt.eml', $emlBytes !== false ? $emlBytes : '');

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Email->value)
        ->set('sourceFormat', 'eml')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors(['file']);
});

it('rejects a sourceFormat that does not belong to the email import type', function (): void {
    $emlBytes = "From: a@b.test\r\nSubject: hi\r\n\r\nBody.\r\n";
    $file = UploadedFile::fake()->createWithContent('msg.eml', $emlBytes);

    // submit() validates the pair, and this cross-product is absent from the
    // import type's own format list.
    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Email->value)
        ->set('sourceFormat', 'asn-csv')
        ->set('file', $file)
        ->call('submit')
        ->assertHasErrors(['sourceFormat']);
});

it('accepts a .mbox file via the extensions: validator even though the OS reports no MIME', function (): void {
    $mbox = "From sender@example.test Thu Jan 01 00:00:00 2026\r\nFrom: sender@example.test\r\nSubject: msg\r\n\r\nBody.\r\n";
    $file = UploadedFile::fake()->createWithContent('archive.mbox', $mbox);

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Email->value)
        ->set('sourceFormat', 'mbox')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors(['file']);
});

it('uses the extensions: validation rule (not mimes:) so unknown-MIME types pass', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../Receipts/tests/fixtures/paypal/current-receipt.eml');
    $file = UploadedFile::fake()->createWithContent('msg.eml', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Email->value)
        ->set('sourceFormat', 'eml')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors(['file']);
});

it('rejects a non-.eml/.mbox extension on the email import type', function (): void {
    $file = UploadedFile::fake()->createWithContent('not-an-eml.bin', 'arbitrary bytes');

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Email->value)
        ->set('sourceFormat', 'eml')
        ->set('file', $file)
        ->call('submit')
        ->assertHasErrors(['file']);
});

// `max:` counts kilobytes on a file rule, and these two ceilings were written
// as byte counts — 1 GiB for .mbox and 20 MiB for .eml, both above the 20M
// upload_max_filesize the desktop runtime pins, so the promised ceiling was
// unreachable and an oversized upload died as an opaque POST failure.
it('holds an email upload to the documented ceiling for its format', function (string $format, string $name, int $maxKb): void {
    $oversized = UploadedFile::fake()->create($name, $maxKb + 1);

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Email->value)
        ->set('sourceFormat', $format)
        ->set('file', $oversized)
        ->call('submit')
        ->assertHasErrors(['file']);
})->with([
    'eml at 20 KB' => ['eml', 'huge.eml', 20],
    'mbox at 1 MB' => ['mbox', 'huge.mbox', 1024],
]);

it('still accepts an email file sitting exactly on its ceiling', function (string $format, string $name, int $maxKb): void {
    $atLimit = UploadedFile::fake()->create($name, $maxKb);

    Livewire::test(UploadWizard::class)
        ->set('importType', ImportType::Email->value)
        ->set('sourceFormat', $format)
        ->set('file', $atLimit)
        ->call('submit')
        ->assertHasNoErrors(['file']);
})->with([
    'eml at 20 KB' => ['eml', 'ceiling.eml', 20],
    'mbox at 1 MB' => ['mbox', 'ceiling.mbox', 1024],
]);

// The ceiling the reader is promised has to be one the runtime can deliver.
it('keeps every wizard ceiling under the upload_max_filesize the desktop runtime pins', function (): void {
    expect(UploadLimits::MAX_KB)->toBeLessThanOrEqual(20 * 1024);

    foreach (UploadWizard::SUPPORTED_FORMATS as $format) {
        $component = Livewire::test(UploadWizard::class);
        /** @var UploadWizard $instance */
        $instance = $component->instance();
        $instance->sourceFormat = $format;

        $fileRules = $instance->rules()['file'];
        $max = null;
        foreach ($fileRules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                $max = (int) substr($rule, 4);
            }
        }

        expect($max)->not->toBeNull("No max: rule for {$format}");
        expect($max)->toBeLessThanOrEqual(20 * 1024, "The {$format} ceiling is above upload_max_filesize.");
    }
});
