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

/*
 * SearchEncryptionFallbackTest — 14.1-09 (D-06): closes the Search
 * fallback/entity cluster the CONTEXT snapshot missed entirely.
 * `SearchQuery::likeFallbackIds`, `EntityNameSearch`, and
 * `DidYouMeanSuggester` all used to predicate directly on ciphertext
 * columns (`counterparty_name`/`description`/`display_name`) and would
 * silently return zero/empty/null hits for a genuinely encrypted user.
 * Every test here runs against a REAL encrypted user
 * (`EnablesEncryptionForUser`) whose fixtures are written through the
 * actual encrypting write path (`RecordTransactions` — mirrors
 * `FtsSurvivesEncryptionTest`'s "real import path" test) rather than a
 * raw plaintext DB insert, so a still-broken predicate would genuinely
 * fail here, not pass vacuously against a plaintext fixture.
 *
 * The primary FTS path (>= 3-char queries) is unaffected by this plan —
 * `FtsSurvivesEncryptionTest` already proves it works via the decrypted
 * plaintext shadow (D-02c). This file only covers the fallback/entity
 * paths this plan converts.
 */

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

/**
 * Records ONE transaction through the real encrypting write path
 * (`RecordTransactions`) and returns its id, looked up via the
 * non-sensitive `source_ref` column (`counterparty_name`/`description`
 * are ciphertext at rest post-write and cannot be used to look the row
 * back up).
 */
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
            fxRateUsed: null,
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

    // Confirm this row genuinely went through the encrypt hook — ciphertext
    // at rest, not the plaintext fixture value.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $stored = $db->connection()->table('transactions')->where('id', $txId)->first(['counterparty_name']);
    expect($stored->counterparty_name)->not->toBe('Zx Superstore');

    /** @var SearchQuery $query */
    $query = app(SearchQuery::class);

    // 'zx' is a 2-char query — below the FTS5 trigram floor (Pitfall-6), so
    // this exercises ONLY likeFallbackIds(). Lowercase input also proves the
    // decrypt-then-substring match is case-insensitive, mirroring the old
    // SQL LIKE's default case-insensitivity.
    $page = $query->search($user, 'zx', SearchFilters::empty());

    expect(collect($page->rows)->pluck('id'))->toContain($txId);
})->group('SearchEncryptionFallback');

it('the short-query fallback candidate scan is bounded, not an unbounded full-history decrypt (Task 1)', function (): void {
    // RESEARCH Pitfall 3 / T-14.1-06: assert the fallback's candidate
    // window is a genuinely bounded constant (not, say, PHP_INT_MAX or an
    // unbounded ->get() with no limit()) — the mechanism a naive
    // full-history decrypt regression would violate. The cap now lives on
    // FtsCandidateResolver, which owns the fallback scan.
    $reflection = new ReflectionClass(FtsCandidateResolver::class);
    $constant = $reflection->getConstant('LIKE_FALLBACK_CANDIDATE_CAP');

    expect($constant)->toBeInt();
    expect($constant)->toBeGreaterThan(0);
    // A cap in the low thousands (or fewer) is a genuine bound; anything
    // approaching PHP_INT_MAX would defeat the point of bounding at all.
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

    // 3+ char query — goes through the FTS5 MATCH path (decrypted plaintext
    // shadow, D-02c), never through likeFallbackIds. Unaffected by this plan.
    $page = $query->search($user, 'groceries', SearchFilters::empty());

    expect(collect($page->rows)->pluck('id'))->toContain($txId);
})->group('SearchEncryptionFallback');

it('EntityNameSearch (⌘K) matches a decrypted counterparty display_name for an encrypted user (Task 2)', function (): void {
    $user = sefUser();
    $this->enablesEncryptionForUser($user);

    $account = sefAccount($user);

    // Same merchant-alias creation-write fixture as CounterpartyEncryptionTest
    // — CounterpartyResolverService encrypts display_name/merchant_name on
    // creation (already-fixed precedent), giving us a genuinely encrypted
    // counterparties row rather than a raw plaintext insert.
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
        fxRateUsed: null,
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

    // Ciphertext at rest — proves this went through the real encrypting write.
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

    // "heijm" (a 1-edit typo of "heijn") matches nothing via FTS or the LIKE
    // fallback — the zero-result search then falls through to
    // DidYouMeanSuggester, which must decrypt the stored counterparty_name
    // to build its corpus (it is ciphertext at rest) before it can find
    // "heijn" as the closest word.
    $page = $query->search($user, 'heijm', SearchFilters::empty());

    expect($page->totalCount)->toBe(0);
    expect($page->didYouMean)->toBe('heijn');
})->group('SearchEncryptionFallback');

it('the did-you-mean candidate row scan is bounded, not an unbounded full-history decrypt (Task 2)', function (): void {
    // RESEARCH Pitfall 3 / T-14.1-06: DidYouMeanSuggester can no longer
    // GROUP BY the ciphertext counterparty_name column, so it must fetch
    // ungrouped rows to decrypt-then-tally in PHP — assert that fetch is
    // capped at a genuinely bounded constant, not an unbounded ->get().
    $reflection = new ReflectionClass(DidYouMeanSuggester::class);
    $constant = $reflection->getConstant('CANDIDATE_ROW_CAP');

    expect($constant)->toBeInt();
    expect($constant)->toBeGreaterThan(0);
    expect($constant)->toBeLessThan(100_000);
})->group('SearchEncryptionFallback');
