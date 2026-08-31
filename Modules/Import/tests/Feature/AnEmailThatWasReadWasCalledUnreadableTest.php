<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Ledger\Models\ImportRun;
use Tests\Helpers\UploadIsolation;

// A .eml drop is read by the receipt recorder, which stores the message and
// asks the matchers what it is. A message it stores but cannot read a payment
// out of yields no source row, and the wizard -- which had no idea an email had
// been read at all -- answered "This file could not be read" over a receipt it
// had just decoded, dated and filed. The reader was shown nothing about the
// message they had captured, so re-uploading it was the only move left.

beforeEach(function (): void {
    UploadIsolation::isolate();

    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
    $this->actingAs($this->fixtureUser);
});

function dropEmailFile(string $path, string $format = 'eml'): int
{
    $bytes = (string) file_get_contents($path);

    Livewire::test(UploadWizard::class)
        ->set('importType', 'email')
        ->set('sourceFormat', $format)
        ->set('file', UploadedFile::fake()->createWithContent(basename($path), $bytes))
        ->call('submit')
        ->assertHasNoErrors();

    return (int) ImportRun::query()->latest('id')->value('id');
}

function emailScanFixture(string $relative): string
{
    return __DIR__.'/../../../EmailScan/tests/fixtures/eml/'.$relative;
}

it('does not call an email unreadable when it read, dated and filed the receipt', function (string $fixture, string $subject, string $sender): void {
    $runId = dropEmailFile(emailScanFixture($fixture));

    $stored = DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->first();
    expect($stored)->not->toBeNull();
    expect($stored->subject)->toBe($subject);

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->assertDontSee(__('import::preview.failed.heading'))
        ->assertDontSee(__('import::preview.failed.no_rows'))
        ->assertSee(__('import::preview.receipts.heading'))
        ->assertSee($subject)
        ->assertSee($sender);
})->with([
    'a PayPal receipt whose wording the matcher cannot read' => [
        'paypal/sample-receipt.eml',
        'Bedankt voor je betaling aan Synthetic Merchant BV',
        'service@paypal.com',
    ],
    'an ICS statement notice' => [
        'ics/sample-statement-notice.eml',
        'Je nieuwe maandafschrift staat klaar',
        'noreply@ics.nl',
    ],
    'a Google Play order receipt' => [
        'googleplay/sample-purchase.eml',
        'Your Google Play Order Receipt',
        'googleplay-noreply@google.com',
    ],
]);

// Three messages, three different answers: one becomes a transaction, one comes
// from a sender no matcher reads, and one is a sign-in notice a matcher refuses
// on purpose. The two that yielded no row were dropped in silence.
it('accounts for every message in a mailbox archive, not only the ones that became rows', function (): void {
    $runId = dropEmailFile(__DIR__.'/../../../Receipts/tests/fixtures/mbox/paypal-mixed.mbox', 'mbox');

    expect(DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->count())->toBe(3);

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->assertSee(__('import::preview.receipts.heading'))
        ->assertSee('Je ontvangstbewijs van Netflix BV')
        ->assertSee('Your bill is ready')
        ->assertSee('New device sign-in')
        ->assertSee(__('import::preview.receipts.state.read'))
        ->assertSee(__('import::preview.receipts.state.unknown_sender'))
        ->assertSee(__('import::preview.receipts.state.not_a_payment'));
});

// The receipt that parses reaches the ledger through the same screen, so the
// panel must not turn the working path into a warning.
it('still confirms the email drop that did become a transaction', function (): void {
    $runId = dropEmailFile(__DIR__.'/../../../Receipts/tests/fixtures/paypal/current-receipt.eml');

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->assertDontSee(__('import::preview.failed.heading'))
        ->assertSee(__('import::preview.receipts.state.read'))
        ->call('confirm')
        ->assertRedirect(route('imports.results', ['id' => $runId]));
});

// A file that is not an email at all still has to say so. The recorder writes
// its audit row either way, so "a row exists" cannot be what the screen reads.
it('still says a file that carries no message could not be read', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'not-an-email').'.eml';
    file_put_contents($path, random_bytes(512));

    $runId = dropEmailFile($path);

    Livewire::test(PreviewWizard::class, ['id' => $runId])
        ->assertSee(__('import::preview.failed.heading'))
        ->assertDontSee(__('import::preview.receipts.heading'));
});

// Matching is not deferred. `receipts.process-fetched-inbox-messages` is
// desktop-only by decision and `receipts.scan-drop-folder` watches a folder
// nothing on a phone writes into, so an upload is in practice the only way a
// phone reaches the recorder — and a receipt that had to wait for a queue
// worker or a scheduler tick there would wait far longer than a request.
it('answers the matchers inside the upload request, leaving nothing on a queue', function (): void {
    Queue::fake();

    dropEmailFile(__DIR__.'/../../../Receipts/tests/fixtures/paypal/current-receipt.eml');

    Queue::assertNothingPushed();

    $row = DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->first();
    expect($row->status)->toBe('parsed');
    expect($row->matcher_key)->toBe('paypal-receipt');
});
