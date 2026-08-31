<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Search\Internal\Services\DidYouMeanSuggester;
use Modules\Search\Internal\Services\EntityNameSearch;
use Modules\Search\Internal\Services\FtsCandidateResolver;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(EnablesEncryptionForUser::class);

// The fallback and entity lookups used to predicate straight on the ciphertext
// columns and returned nothing for an encrypted user. Every fixture here is
// written through the real encrypting path, so a predicate that still reads
// ciphertext fails outright instead of passing against a plaintext row.

function sefUser(): User
{
    return User::query()->create([
        'username' => 'sef-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function sefAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN sef',
        'slug' => 'sef-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

function sefImportRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sef-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'sef-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);
}

function sefRecordTransaction(User $user, Account $account, ImportRun $run, string $counterpartyName, string $description, string $sourceRef): int
{
    /** @var RecordTransactions $action */
    $action = app(RecordTransactions::class);
    $action([
        new CanonicalTransaction(
            userId: $user->id,
            accountId: $account->id,
            type: 'expense',
            postedAt: CarbonImmutable::parse('2026-06-01'),
            bookedAt: CarbonImmutable::parse('2026-06-01 12:00:00'),
            valueDate: CarbonImmutable::parse('2026-06-01'),
            amountMinor: -1099,
            currency: 'EUR',
            settledAmountMinor: -1099,
            settledCurrency: 'EUR',
            counterpartyName: $counterpartyName,
            counterpartyIban: null,
            counterpartyNormalized: strtolower($counterpartyName),
            normalizationVersion: 1,
            description: $description,
            categoryId: null,
            sourceFormat: 'asn-csv',
            importRunId: $run->id,
            sourceRowIndex: 0,
            sourceRef: $sourceRef,
        ),
    ], $user);

    // counterparty_name and description are ciphertext the moment the write
    // lands, so source_ref is the only column left to find the row by.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $stored = $db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('source_ref', $sourceRef)
        ->first(['id']);

    expect($stored)->not->toBeNull();

    return is_numeric($stored->id) ? (int) $stored->id : 0;
}

it('a short (<3-char) query fallback returns hits for a genuinely encrypted user (Task 1)', function (): void {
    $user = sefUser();
    $this->enablesEncryptionForUser($user);

    $account = sefAccount($user);
    $run = sefImportRun($user);

    $txId = sefRecordTransaction($user, $account, $run, 'Zx Superstore', 'weekly shop', 'sef-like-1');

    // Ciphertext at rest proves the row went through the real encrypt hook.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $stored = $db->connection()->table('transactions')->where('id', $txId)->first(['counterparty_name']);
    expect($stored->counterparty_name)->not->toBe('Zx Superstore');

    /** @var SearchQuery $query */
    $query = app(SearchQuery::class);

    // Two characters is below the FTS5 trigram floor, so only likeFallbackIds
    // runs. The lowercase input also pins the decrypt-then-substring match as
    // case-insensitive, the way the SQL LIKE it replaced was.
    $page = $query->search($user, 'zx', SearchFilters::empty());

    expect(collect($page->rows)->pluck('id'))->toContain($txId);
})->group('SearchEncryptionFallback');

it('the short-query fallback candidate scan is bounded, not an unbounded full-history decrypt (Task 1)', function (): void {
    // Matching by decrypting means the scan has to stop somewhere, and this
    // constant is what a naive full-history decrypt would have to remove.
    $reflection = new ReflectionClass(FtsCandidateResolver::class);
    $constant = $reflection->getConstant('LIKE_FALLBACK_CANDIDATE_CAP');

    expect($constant)->toBeInt();
    expect($constant)->toBeGreaterThan(0);
    // A cap anywhere near PHP_INT_MAX would be no bound at all.
    expect($constant)->toBeLessThan(100_000);
})->group('SearchEncryptionFallback');

it('the primary >= 3-char FTS path is unaffected by the fallback fix, for an encrypted user (Task 1)', function (): void {
    $user = sefUser();
    $this->enablesEncryptionForUser($user);

    $account = sefAccount($user);
    $run = sefImportRun($user);

    $txId = sefRecordTransaction($user, $account, $run, 'Albert Heijn', 'weekly groceries run', 'sef-fts-1');

    /** @var SearchQuery $query */
    $query = app(SearchQuery::class);

    // Three characters or more goes through the FTS5 MATCH path over the
    // plaintext shadow, never through likeFallbackIds.
    $page = $query->search($user, 'groceries', SearchFilters::empty());

    expect(collect($page->rows)->pluck('id'))->toContain($txId);
})->group('SearchEncryptionFallback');

it('EntityNameSearch (⌘K) matches a decrypted counterparty display_name for an encrypted user (Task 2)', function (): void {
    $user = sefUser();
    $this->enablesEncryptionForUser($user);

    $account = sefAccount($user);

    // Going through CounterpartyResolverService encrypts display_name on
    // creation, so the counterparties row is genuinely ciphertext rather than
    // a raw plaintext insert.
    DB::table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => 'NETFLIX.COM AMSTERDAM',
        'generalized_pattern' => 'netflix',
        'friendly_name' => 'Netflix',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $tx = new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'expense',
        postedAt: CarbonImmutable::create(2026, 6, 1, 12, 0, 0),
        bookedAt: CarbonImmutable::create(2026, 6, 1, 12, 0, 0),
        valueDate: CarbonImmutable::create(2026, 6, 1, 12, 0, 0),
        amountMinor: -1099,
        currency: 'EUR',
        settledAmountMinor: -1099,
        settledCurrency: 'EUR',
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: 'fixture',
        normalizationVersion: 1,
        description: 'NETFLIX.COM AMSTERDAM',
        categoryId: null,
        sourceFormat: 'asn_csv',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: null,
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = app(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $user);
    expect($dto)->not->toBeNull();

    // Ciphertext at rest proves this went through the real encrypting write.
    $stored = DB::table('counterparties')->where('id', $dto->counterpartyId)->first(['display_name']);
    expect($stored->display_name)->not->toBe('Netflix');

    /** @var EntityNameSearch $entitySearch */
    $entitySearch = app(EntityNameSearch::class);
    $hits = $entitySearch->query($user, 'net');

    $counterpartyLabels = collect($hits)->where('type', 'counterparty')->pluck('label');
    expect($counterpartyLabels->all())->toContain('Netflix');
})->group('SearchEncryptionFallback');

it('did-you-mean suggests a close match derived from decrypted counterparty_name for an encrypted user (Task 2)', function (): void {
    $user = sefUser();
    $this->enablesEncryptionForUser($user);

    $account = sefAccount($user);
    $run = sefImportRun($user);

    sefRecordTransaction($user, $account, $run, 'Albert Heijn', 'weekly groceries run', 'sef-dym-1');

    /** @var SearchQuery $query */
    $query = app(SearchQuery::class);

    // "heijm" is a one-edit typo that matches nothing via FTS or the fallback,
    // so the zero-result path hands over to DidYouMeanSuggester, which has to
    // decrypt counterparty_name before it has a corpus to suggest from.
    $page = $query->search($user, 'heijm', SearchFilters::empty());

    expect($page->totalCount)->toBe(0);
    expect($page->didYouMean)->toBe('heijn');
})->group('SearchEncryptionFallback');

it('the did-you-mean candidate row scan is bounded, not an unbounded full-history decrypt (Task 2)', function (): void {
    // GROUP BY over a ciphertext column tallies nothing, so the suggester
    // fetches ungrouped rows and tallies in PHP after decrypting — which only
    // stays affordable while that fetch is capped.
    $reflection = new ReflectionClass(DidYouMeanSuggester::class);
    $constant = $reflection->getConstant('CANDIDATE_ROW_CAP');

    expect($constant)->toBeInt();
    expect($constant)->toBeGreaterThan(0);
    expect($constant)->toBeLessThan(100_000);
})->group('SearchEncryptionFallback');
