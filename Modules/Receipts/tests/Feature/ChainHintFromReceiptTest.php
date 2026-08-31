<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Enums\ChainHintType;
use Modules\Receipts\Public\Events\ChainHintDetected;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
    $this->icsAccount = $seeded['icsAccount'];
    $this->actingAs($this->fixtureUser);
});

it('lands a transaction + a candidate chain_links row from an ICS receipt with a card last-four', function (): void {
    $emlBytes = (string) file_get_contents(__DIR__.'/../fixtures/ics/current-receipt.eml');
    $file = UploadedFile::fake()->createWithContent('ics-receipt.eml', $emlBytes);

    Livewire::test(UploadWizard::class)
        ->set('importType', 'email')
        ->set('sourceFormat', 'eml')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors();

    $importRunId = ImportRun::query()->latest('id')->value('id');
    expect($importRunId)->not->toBeNull();
    $confirm = $this->app->make(ConfirmsImports::class);
    $confirm($importRunId, $this->fixtureUser);

    $transactions = Transaction::query()->where('user_id', $this->fixtureUser->id)->get();
    expect($transactions)->toHaveCount(1);
    $tx = $transactions->first();
    expect($tx->source_format)->toBe('eml');
    expect($tx->raw_payload)->toBeArray();
    expect($tx->raw_payload['chain_hints'] ?? null)->toBeArray();
    expect($tx->raw_payload['chain_hints'])->toHaveCount(1);
    expect($tx->raw_payload['chain_hints'][0]['hint_type'])->toBe('funded_by_card');
    expect($tx->raw_payload['chain_hints'][0]['card_last4'])->toBe('1234');

    $chainLinks = DB::table('chain_links')
        ->where('user_id', $this->fixtureUser->id)
        ->get();
    expect($chainLinks)->toHaveCount(1);
    $link = $chainLinks->first();
    expect($link->kind)->toBe('funded_by_card_hint');
    expect($link->state)->toBe('candidate');
    expect($link->from_transaction_id)->toBe((int) $tx->id);
    expect($link->to_transaction_id)->toBeNull();
    expect($link->resolver)->toBe('auto');
    // The column stores confidence as a decimal string.
    expect((float) $link->confidence)->toBeGreaterThan(0.0);

    $evidence = json_decode((string) $link->evidence, associative: true);
    expect($evidence)->toBeArray();
    expect($evidence['card_last4'])->toBe('1234');
});

it('dispatches ChainHintDetected with the canonical transaction id and the importing user id', function (): void {
    Event::fake([ChainHintDetected::class]);

    $emlBytes = (string) file_get_contents(__DIR__.'/../fixtures/ics/current-receipt.eml');
    $file = UploadedFile::fake()->createWithContent('ics-receipt.eml', $emlBytes);

    Livewire::test(UploadWizard::class)
        ->set('importType', 'email')
        ->set('sourceFormat', 'eml')
        ->set('file', $file)
        ->call('submit')
        ->assertHasNoErrors();

    $importRunId = ImportRun::query()->latest('id')->value('id');
    $confirm = $this->app->make(ConfirmsImports::class);
    $confirm($importRunId, $this->fixtureUser);

    $tx = Transaction::query()->where('user_id', $this->fixtureUser->id)->firstOrFail();

    Event::assertDispatched(ChainHintDetected::class, function (ChainHintDetected $event) use ($tx): bool {
        return $event->sourceTransactionId === (int) $tx->id
            && $event->userId === $this->fixtureUser->id
            && $event->hintType === ChainHintType::FundedByCard
            && $event->hintPayload instanceof FundedByCardPayload
            && $event->hintPayload->cardLast4 === '1234';
    });
});

it('does not dispatch ChainHintDetected from ParseStage (the bridge is post-persistence)', function (): void {
    $spy = new class
    {
        /** @var list<object> */
        public array $events = [];

        public function record(object $event): void
        {
            $this->events[] = $event;
        }
    };
    /** @var Dispatcher $dispatcher */
    $dispatcher = $this->app->make(Dispatcher::class);
    $dispatcher->listen(ChainHintDetected::class, [$spy, 'record']);

    $bytes = (string) file_get_contents(__DIR__.'/../fixtures/ics/current-receipt.eml');
    $tmp = tempnam(sys_get_temp_dir(), 'chainhint-eml-');
    if ($tmp === false) {
        $this->fail('Could not allocate a temp file for the fixture.');
    }
    file_put_contents($tmp, $bytes);
    try {
        $parseStage = $this->app->make(ParseStage::class);
        $rows = iterator_to_array(
            $parseStage->run($tmp, 'eml', new ChainHintFromReceiptTestAccountResolver, $this->fixtureUser),
            false,
        );
        expect($rows)->toHaveCount(1);
    } finally {
        @unlink($tmp);
    }

    // The hint is dispatched by DispatchChainHintsFromReceipt on
    // TransactionImported, which cannot have fired with no row inserted.
    expect($spy->events)->toBe([]);
});

// The second drop short-circuits on the file_imports UNIQUE, so what is covered
// here is the wizard path; the chain_links idempotency check on
// (user, from, kind) is never reached.
it('keeps chain_links at exactly one row across re-drops of the same .eml', function (): void {
    $emlBytes = (string) file_get_contents(__DIR__.'/../fixtures/ics/current-receipt.eml');

    $drop = function () use ($emlBytes): void {
        $file = UploadedFile::fake()->createWithContent('ics-receipt.eml', $emlBytes);
        Livewire::test(UploadWizard::class)
            ->set('importType', 'email')
            ->set('sourceFormat', 'eml')
            ->set('file', $file)
            ->call('submit');
        $importRunId = ImportRun::query()->latest('id')->value('id');
        if ($importRunId !== null) {
            $confirm = $this->app->make(ConfirmsImports::class);
            $confirm($importRunId, $this->fixtureUser);
        }
    };
    $drop();
    $drop();

    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
    expect(DB::table('chain_links')->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
});

final class ChainHintFromReceiptTestAccountResolver implements AccountResolver
{
    public function resolve(string $iban): AccountResolution
    {
        // The spy test only iterates the row generator and never writes, so any
        // non-null account id will do.
        return AccountResolution::known(1);
    }
}
