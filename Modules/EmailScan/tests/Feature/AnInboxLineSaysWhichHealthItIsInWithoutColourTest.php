<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;

// Three of the tile's four states print the same "last scanned <when>" line, so
// the dot's hue was the whole of the judgement — and the dot is aria-hidden, so
// for a reader who hears the tile there was no judgement at all. A mailbox that
// stopped scanning a fortnight ago and one scanned an hour ago said the same
// thing in the same words.

function inboxHealthWordUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function inboxHealthWordInbox(User $owner, string $email, ?string $lastScanAt): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
        'email' => $email,
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'last_scan_at' => $lastScanAt,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// The dashboard route redirects a ledger with nothing in it to /imports/new, so
// the tile needs one transaction behind it before the page it sits on renders.
function inboxHealthWordLedger(User $owner): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $hex = bin2hex(random_bytes(4));
    $now = CarbonImmutable::now()->toDateTimeString();

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $owner->id, 'name' => 'Bank '.$hex, 'slug' => 'bank-'.$hex, 'kind' => 'bank',
        'iban' => 'GB00BANK'.strtoupper($hex), 'default_currency' => 'EUR',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $owner->id, 'source_format' => 'revolut-csv', 'raw_file_path' => '/tmp/run-'.$hex.'.csv',
        'sha256' => hash('sha256', 'run-'.$hex), 'uploaded_at' => $now, 'status' => 'committed',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $owner->id, 'account_id' => $accountId, 'import_run_id' => $runId,
        'counterparty_id' => null, 'category_id' => null,
        'fingerprint' => hash('sha256', 'fp-'.$hex), 'fingerprint_version' => 3,
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => $now, 'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => -1_000, 'currency' => 'EUR',
        'settled_amount_minor' => -1_000, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'grocer', 'counterparty_name' => 'Grocer BV',
        'normalization_version' => 1, 'description' => 'fixture',
        'type' => 'expense', 'source_format' => 'revolut-csv', 'source_row_index' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);
}

/**
 * The reading of every tile line, in the order the tile lists them. The reading
 * carries no attributes, so the dot's class cannot answer a question asked here.
 *
 * @return list<string>
 */
function inboxHealthWordReadings(string $html): array
{
    return array_map(
        static fn (RenderedMarkup $line): string => $line->text(),
        RenderedMarkup::of($html)->all('[data-testid="email-scan-health-line"]'),
    );
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-17 12:00:00'));
    putenv('NATIVEPHP_PLATFORM');
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    putenv('NATIVEPHP_PLATFORM');
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
});

it('tells a mailbox that has fallen behind from one that has not, with no colour in the reading', function (): void {
    $reader = inboxHealthWordUser('inbox-health-word');
    inboxHealthWordLedger($reader);
    inboxHealthWordInbox($reader, 'fresh@example.com', CarbonImmutable::now()->subHours(3)->toDateTimeString());
    inboxHealthWordInbox($reader, 'behind@example.com', CarbonImmutable::now()->subHours(30)->toDateTimeString());

    $response = $this->actingAs($reader)->get('/');
    $response->assertOk();

    $readings = inboxHealthWordReadings($response->getContent() ?: '');

    expect($readings)->toHaveCount(2);

    $outOfDate = Lang::get('email-scan::health.out_of_date');

    expect($readings[1])->toContain($outOfDate)
        ->and($readings[0])->not->toContain($outOfDate)
        ->and($readings[0])->not->toContain(Lang::get('email-scan::health.not_scanned_here'));
});

it('says a device that schedules no scan is not the one scanning, rather than tinting it grey', function (): void {
    $reader = inboxHealthWordUser('inbox-health-phone');
    inboxHealthWordLedger($reader);
    inboxHealthWordInbox($reader, 'phone@example.com', CarbonImmutable::now()->subHours(30)->toDateTimeString());

    putenv('NATIVEPHP_PLATFORM=ios');

    $response = $this->actingAs($reader)->get('/');
    $response->assertOk();

    $readings = inboxHealthWordReadings($response->getContent() ?: '');

    expect($readings)->toHaveCount(1);

    // The phone is not behind a schedule it never had, so it must not read as
    // out of date either — the two greys said one thing between them before.
    expect($readings[0])->toContain(Lang::get('email-scan::health.not_scanned_here'))
        ->and($readings[0])->not->toContain(Lang::get('email-scan::health.out_of_date'));
});
