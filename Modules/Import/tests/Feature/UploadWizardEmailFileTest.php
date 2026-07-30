<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('exposes email-file as a valid issuer with two leaf formats per the UI-SPEC', function (): void {
    $component = Livewire::test(UploadWizard::class)->set('issuer', 'email-file');
    /** @var UploadWizard $instance */
    $instance = $component->instance();

    $available = $instance->availableFormats();

    expect($available)->toBe([
        ['value' => 'eml', 'label' => 'Email message (.eml)'],
        ['value' => 'mbox', 'label' => 'Mailbox archive (.mbox)'],
    ]);
});

it('accepts an .eml upload under the email-file issuer', function (): void {
    $emlBytes = file_get_contents(__DIR__.'/../../../Receipts/tests/fixtures/paypal/current-receipt.eml');
    $file = UploadedFile::fake()->createWithContent('paypal-receipt.eml', $emlBytes !== false ? $emlBytes : '');

    Livewire::test(UploadWizard::class)
        ->set('issuer', 'email-file')
        ->set('sourceFormat', 'eml')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors(['file']);
});

it('rejects a sourceFormat that does not belong to the email-file issuer', function (): void {
    $emlBytes = "From: a@b.test\r\nSubject: hi\r\n\r\nBody.\r\n";
    $file = UploadedFile::fake()->createWithContent('msg.eml', $emlBytes);

    // sourceFormat='asn-csv' under issuer='email-file' must fail
    // because the cross-product is not in the SUPPORTED_FORMATS map
    // for that issuer (the wizard's submit() validates both).
    Livewire::test(UploadWizard::class)
        ->set('issuer', 'email-file')
        ->set('sourceFormat', 'asn-csv')
        ->set('file', $file)
        ->call('submit')
        ->assertHasErrors(['sourceFormat']);
});

it('accepts a .mbox file via the extensions: validator even though the OS reports no MIME', function (): void {
    $mbox = "From sender@example.test Thu Jan 01 00:00:00 2026\r\nFrom: sender@example.test\r\nSubject: msg\r\n\r\nBody.\r\n";
    $file = UploadedFile::fake()->createWithContent('archive.mbox', $mbox);

    Livewire::test(UploadWizard::class)
        ->set('issuer', 'email-file')
        ->set('sourceFormat', 'mbox')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors(['file']);
});

it('uses the extensions: validation rule (not mimes:) so unknown-MIME types pass', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../Receipts/tests/fixtures/paypal/current-receipt.eml');
    $file = UploadedFile::fake()->createWithContent('msg.eml', $contents !== false ? $contents : '');

    Livewire::test(UploadWizard::class)
        ->set('issuer', 'email-file')
        ->set('sourceFormat', 'eml')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors(['file']);
});

it('rejects a non-.eml/.mbox extension on the email-file arm', function (): void {
    $file = UploadedFile::fake()->createWithContent('not-an-eml.bin', 'arbitrary bytes');

    Livewire::test(UploadWizard::class)
        ->set('issuer', 'email-file')
        ->set('sourceFormat', 'eml')
        ->set('file', $file)
        ->call('submit')
        ->assertHasErrors(['file']);
});
