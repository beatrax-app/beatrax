<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\CardStatementCredit;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Transaction;

// The ASN entry paying the ICS card names its counterparty on the Othr branch
// of CdtrAcct/Id, and the card answers to the synthetic literal in its own
// `iban` column rather than to an alias row. Either one read alone left the
// payment invisible to the settlement pass and /chains empty.
/**
 * @link ../fixtures/scenario-1/scenario-1.md
 */
beforeEach(function (): void {
    // The day after the ASN settlement (2026-05-19), which is where the reader
    // stands when the dashboard reports the statement overdue.
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');

    /** @var array{user: User} $seed */
    $seed = $this->seedFixtureUserAndAccount();
    $this->user = $seed['user'];
    $this->actingAs($this->user);

    $fixtures = base_path('Modules/Chains/tests/fixtures/scenario-1');

    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $importer->runAndConfirm($fixtures.'/ics-statement.pdf', 'ics-pdf', $this->user);
    $importer->runAndConfirm($fixtures.'/paypal-activity.csv', 'paypal-csv', $this->user);
    $importer->runAndConfirm($fixtures.'/asn-camt053.xml', 'camt053', $this->user);

    /** @var Transaction $settlement */
    $settlement = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('source_format', 'camt053')
        ->sole();
    $this->settlement = $settlement;
});

it('carries the Othr counterparty identifier onto the imported ASN settlement row', function (): void {
    expect($this->settlement->amount_minor)->toBe(-84732);
    expect($this->settlement->counterparty_name)->toBe('ICS Cards Nederland');
    expect($this->settlement->counterparty_iban)->toBe('ICS-CARD');
    expect($this->settlement->type)->toBe('transfer_out');
});

it('reaches the statement that payment settles, which it could not name at all before', function (): void {
    $links = ChainLink::query()
        ->where('user_id', $this->user->id)
        ->where('kind', 'ics_bulk_settle')
        ->get();

    expect($links)->not->toBeEmpty();
    expect($links->pluck('from_transaction_id')->unique()->values()->all())->toBe([$this->settlement->id]);

    /** @var CardStatement $statement */
    $statement = CardStatement::query()->where('user_id', $this->user->id)->sole();
    expect($links->pluck('evidence.statement_id')->unique()->values()->all())->toBe([$statement->id]);
});

// The period was the min/max BOOKED day while membership is tested on
// posted_at, and ICS books a charge a day or more after the card was used.
// min(booked) therefore always exceeded min(posted): the SPOTIFY charge posted
// 15 April and the NETFLIX charge posted 16 April fell outside the 17-April
// period of the very statement that billed them, and their 999 + 1599 came
// back as the settlement's unaccounted delta on every ICS statement there is.
it('opens the statement on the earliest day it bills, so no charge sits outside its own period', function (): void {
    /** @var CardStatement $statement */
    $statement = CardStatement::query()->where('user_id', $this->user->id)->sole();

    $charges = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('account_id', $statement->account_id)
        ->orderBy('posted_at')
        ->get();

    expect($charges)->toHaveCount(23);
    expect($statement->period_start->toDateString())->toBe('2026-04-15');
    expect($charges->first()->posted_at->toDateString())->toBe('2026-04-15');
    expect($charges->first()->booked_at->toDateString())->toBe('2026-04-17');

    $outside = $charges->filter(fn (Transaction $tx): bool => $tx->posted_at->toDateString() < $statement->period_start->toDateString()
        || $tx->posted_at->toDateString() > $statement->period_end->toDateString());

    expect($outside->pluck('settled_amount_minor')->all())->toBe([]);
});

// The contract scenario-1.md states for the clean variant: every one of the 23
// charges covered, nothing unaccounted, settled, and no surplus carried.
/**
 * @link ../fixtures/scenario-1/scenario-1.md
 */
it('settles the statement the ASN payment paid, to the cent', function (): void {
    $links = ChainLink::query()
        ->where('user_id', $this->user->id)
        ->where('kind', 'ics_bulk_settle')
        ->get();

    expect($links)->toHaveCount(23);

    $evidence = $links->first()->evidence;
    expect($evidence['covered_count'])->toBe(23);
    expect($evidence['unaccounted_delta_minor'])->toBe(0);
    expect($evidence['tolerance_used'])->toBe('amount_5eur');

    /** @var CardStatement $statement */
    $statement = CardStatement::query()->where('user_id', $this->user->id)->sole();
    expect($statement->state)->toBe('settled');
    expect($statement->open_balance_minor)->toBe(0);

    expect(CardStatementCredit::query()->where('user_id', $this->user->id)->sum('amount_minor'))->toBe(0);
});

// Nothing computes the overdue banner independently: it reads the statement
// state through this query, so closing the statement is the whole of clearing
// it. The dashboard reported this statement overdue on the day the payment for
// it was imported, and went on reporting it for as long as the period excluded
// the charges that kept it open.
it('stops reporting the statement overdue the moment the statement closes', function (): void {
    /** @var CardStatementQuery $query */
    $query = $this->app->make(CardStatementQuery::class);

    expect($query->nextSettlementForUser($this->user))->toBeNull();

    CardStatement::query()->where('user_id', $this->user->id)->update(['state' => 'open']);

    $due = $query->nextSettlementForUser($this->user);

    expect($due)->not->toBeNull();
    expect($due?->accountId)->toBe($this->settlement->account_id);
});
