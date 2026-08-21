<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'card-statement-query',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS card',
        'slug' => 'csq-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/csq.pdf',
        'sha256' => str_repeat('s', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var CardStatementQuery $query */
    $query = $this->app->make(CardStatementQuery::class);
    $this->query = $query;
});

it('openForAccount returns null when no open / partially_settled statement exists', function (): void {
    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -10000,
        'open_balance_minor' => 0,
        'state' => 'settled',
    ]);

    expect($this->query->openForAccount((int) $this->account->id, $this->user))->toBeNull();
});

it('openForAccount returns the most recent open statement', function (): void {
    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-03-01 00:00:00',
        'period_end' => '2026-03-31 23:59:59',
        'total_amount_minor' => -50000,
        'open_balance_minor' => 50000,
        'state' => 'open',
    ]);
    $newer = CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -70000,
        'open_balance_minor' => 70000,
        'state' => 'partially_settled',
    ]);

    $result = $this->query->openForAccount((int) $this->account->id, $this->user);

    expect($result)->not->toBeNull();
    expect((int) $result->id)->toBe((int) $newer->id);
    expect($result->state)->toBe('partially_settled');
});

it('openForAccount isolates by user — other users do not leak through', function (): void {
    $other = User::query()->create([
        'username' => 'csq-other',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $otherAccount = Account::query()->create([
        'user_id' => $other->id,
        'name' => 'Other ICS',
        'slug' => 'csq-ics-other',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $otherRun = ImportRun::query()->create([
        'user_id' => $other->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/csq-other.pdf',
        'sha256' => str_repeat('o', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    CardStatement::query()->create([
        'user_id' => $other->id,
        'account_id' => $otherAccount->id,
        'import_run_id' => $otherRun->id,
        'period_start' => '2026-05-01 00:00:00',
        'period_end' => '2026-05-31 23:59:59',
        'total_amount_minor' => -10000,
        'open_balance_minor' => 10000,
        'state' => 'open',
    ]);

    expect($this->query->openForAccount((int) $otherAccount->id, $this->user))->toBeNull();
});
