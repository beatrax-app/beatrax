<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

const ISB_EXPENSES = 60;

const ISB_ICS_IBAN = 'NL08ABNA0526650664';

function isbUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);

    return $user;
}

function isbAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'isb '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function isbRun(User $user, string $sha, string $format): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => $format,
        'raw_file_path' => '/tmp/isb.file',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-05-01 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function isbTx(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $type, string $postedAt, int $settledMinor, string $normalized, array $overrides = []): int
{
    return (int) $db->connection()->table('transactions')->insertGetId(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Name '.$normalized,
        'counterparty_normalized' => $normalized,
        'normalization_version' => 3,
        'source_format' => $run->source_format,
        'import_run_id' => $run->id,
        'source_row_index' => crc32($normalized.$postedAt) % 100000,
        'fingerprint' => substr(hash('sha256', $normalized.$postedAt.$user->id), 0, 64),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-01 12:00:00',
        'updated_at' => '2026-05-01 12:00:00',
    ], $overrides));
}

function isbStatement(DatabaseManager $db, User $user, Account $card, ImportRun $run, string $start, string $end, int $totalMinor): int
{
    return (int) $db->connection()->table('card_statements')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $card->id,
        'import_run_id' => $run->id,
        'period_start' => $start,
        'period_end' => $end,
        'total_amount_minor' => $totalMinor,
        'open_balance_minor' => abs($totalMinor),
        'currency' => 'EUR',
        'state' => 'open',
        'created_at' => '2026-05-01 12:00:00',
        'updated_at' => '2026-05-01 12:00:00',
    ]);
}

/**
 * A closed April statement covering ISB_EXPENSES card expenses plus a refund
 * that lands after it settles, an open May statement for the refund credit to
 * roll into, two settlement transfers sharing one posted_at, a transfer to an
 * IBAN no alias knows, a transfer with no IBAN at all, and a second user
 * holding the same shape.
 *
 * @return array{user: User, other: User, labels: array<int, string>}
 */
function isbFixture(DatabaseManager $db): array
{
    $user = isbUser('isb-owner');
    $other = isbUser('isb-other');
    $labels = [];

    foreach ([['user', $user], ['other', $other]] as $pair) {
        /** @var string $who */
        $who = $pair[0];
        /** @var User $owner */
        $owner = $pair[1];

        $card = isbAccount($owner, $who.'-ics', 'ics_card', $who.'-ICS-CARD');
        $bank = isbAccount($owner, $who.'-asn', 'bank', 'NL57ASNB012345678'.($who === 'user' ? '9' : '8'));

        $icsRun = isbRun($owner, $who.'i', 'ics-pdf');
        $asnRun = isbRun($owner, $who.'a', 'asn-csv');

        $total = 0;
        for ($i = 1; $i <= ISB_EXPENSES; $i++) {
            $amount = -(1000 + $i);
            $total += $amount;
            // Days 2..28: period_start is a datetime and posted_at a date, so
            // a row dated the first of the month sorts below '2026-04-01
            // 00:00:00' and the period filter drops it.
            $day = str_pad((string) ((($i - 1) % 27) + 2), 2, '0', STR_PAD_LEFT);
            $id = isbTx($db, $owner, $card, $icsRun, 'expense', '2026-04-'.$day, $amount, $who.'-merchant-'.$i);
            $labels[$id] = $who.'-expense-'.$i;
        }

        isbStatement($db, $owner, $card, $icsRun, '2026-04-01 00:00:00', '2026-04-30 23:59:59', $total);
        isbStatement($db, $owner, $card, $icsRun, '2026-05-01 00:00:00', '2026-05-31 23:59:59', -5000);

        // Two settlement transfers on one posted_at: the first to be walked
        // settles the statement, the second finds nothing open left.
        $first = isbTx($db, $owner, $bank, $asnRun, 'transfer_out', '2026-04-29', $total, $who.'-ics-settle-a', ['counterparty_iban' => ISB_ICS_IBAN]);
        $labels[$first] = $who.'-transfer-a';
        $second = isbTx($db, $owner, $bank, $asnRun, 'transfer_out', '2026-04-29', $total, $who.'-ics-settle-b', ['counterparty_iban' => ISB_ICS_IBAN]);
        $labels[$second] = $who.'-transfer-b';

        // An IBAN no alias row knows, and a transfer carrying none at all.
        $stranger = isbTx($db, $owner, $bank, $asnRun, 'transfer_out', '2026-04-20', -2500, $who.'-stranger', ['counterparty_iban' => 'NL91ABNA0417164300']);
        $labels[$stranger] = $who.'-stranger';
        $blank = isbTx($db, $owner, $bank, $asnRun, 'transfer_out', '2026-04-21', -1500, $who.'-no-iban');
        $labels[$blank] = $who.'-no-iban';

        // A refund of expense 7, inside the April period, which the refund
        // pass only walks once April has settled.
        $refund = isbTx($db, $owner, $card, $icsRun, 'refund', '2026-04-25', 1007, $who.'-merchant-7');
        $labels[$refund] = $who.'-refund';
    }

    return ['user' => $user, 'other' => $other, 'labels' => $labels];
}

/**
 * @param  array<int, string>  $labels
 */
function isbDump(DatabaseManager $db, array $labels): string
{
    $links = [];
    $rows = $db->connection()->table('chain_links')
        ->orderBy('from_transaction_id')->orderBy('to_transaction_id')->orderBy('kind')->orderBy('id')
        ->get(['user_id', 'from_transaction_id', 'to_transaction_id', 'kind', 'state', 'confidence', 'resolver', 'evidence'])
        ->all();
    foreach ($rows as $row) {
        $links[] = [
            'user' => (int) $row->user_id,
            'from' => $labels[(int) $row->from_transaction_id] ?? 'unlabelled',
            'to' => $row->to_transaction_id === null ? null : ($labels[(int) $row->to_transaction_id] ?? 'unlabelled'),
            'kind' => (string) $row->kind,
            'state' => (string) $row->state,
            'confidence' => (string) $row->confidence,
            'resolver' => (string) $row->resolver,
            'evidence' => json_decode((string) $row->evidence, true),
        ];
    }

    $statements = $db->connection()->table('card_statements')
        ->orderBy('user_id')->orderBy('account_id')->orderBy('period_start')
        ->get(['user_id', 'period_start', 'period_end', 'total_amount_minor', 'open_balance_minor', 'currency', 'state'])
        ->all();

    $credits = $db->connection()->table('card_statement_credits')
        ->orderBy('from_statement_id')->orderBy('id')
        ->get(['user_id', 'amount_minor', 'currency', 'reason'])
        ->all();

    return (string) json_encode([
        'links' => $links,
        'statements' => $statements,
        'credits' => $credits,
    ], JSON_UNESCAPED_SLASHES);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-30 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('writes exactly the chain_links the row-at-a-time resolver wrote, twice over', function (): void {
    $fixture = isbFixture($this->db);

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $resolver->resolveForUser($fixture['user']);
    $resolver->resolveForUser($fixture['other']);

    $afterFirst = isbDump($this->db, $fixture['labels']);

    $resolver->resolveForUser($fixture['user']);
    $resolver->resolveForUser($fixture['other']);

    expect(isbDump($this->db, $fixture['labels']))->toBe($afterFirst);

    $golden = (string) file_get_contents(__DIR__.'/../fixtures/ics-settlement-links.json');
    expect($afterFirst)->toBe(trim($golden));
});

it('inserts the settled expenses as one statement instead of two queries each', function (): void {
    $fixture = isbFixture($this->db);

    /** @var list<string> $log */
    $log = [];
    $this->db->connection()->listen(static function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $resolver->resolveForUser($fixture['user']);

    $linkInserts = count(array_filter($log, static fn (string $sql): bool => str_starts_with($sql, 'insert into "chain_links"')));
    $linkExists = count(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'from "chain_links"') && str_contains($sql, 'exists')));
    $accountReads = count(array_filter($log, static fn (string $sql): bool => str_starts_with($sql, 'select "iban" from "accounts"')));

    // 60 settled expenses plus one refund: one insert for the expense batch,
    // one for the refund batch. The row-at-a-time shape issued 61 inserts and
    // 61 existence probes.
    expect($linkInserts)->toBe(2);
    expect($linkExists)->toBe(0);
    expect($accountReads)->toBe(0);

    expect($this->db->connection()->table('chain_links')->where('user_id', $fixture['user']->id)->count())
        ->toBe(ISB_EXPENSES + 1);
});

it('resolves a posted_at tie the same way on every run', function (): void {
    $fixture = isbFixture($this->db);

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $resolver->resolveForUser($fixture['user']);

    $settlers = $this->db->connection()->table('chain_links')
        ->where('user_id', $fixture['user']->id)
        ->where('kind', 'ics_bulk_settle')
        ->where('state', 'confirmed')
        ->distinct()
        ->pluck('from_transaction_id')
        ->map(static fn (mixed $id): string => $fixture['labels'][(int) $id] ?? 'unlabelled')
        ->sort()
        ->values()
        ->all();

    // The lower id of the two same-posted_at transfers takes the statement,
    // and the refund leg is the only other confirmed `from`.
    expect($settlers)->toBe(['user-refund', 'user-transfer-a']);
});
