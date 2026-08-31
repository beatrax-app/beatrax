<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Search\Internal\Services\EntityNameSearch;
use Modules\Search\Public\Enums\SearchEntityKind;

// A counterparty the resolver had to name itself stores English in
// `display_name`; `metadata.default_name` says the word is the app's, not the
// reader's. The palette matched the stored word only, so a Dutch reader typing
// the word their own screen shows them — "Onbekend" — found nothing, and the
// row that did come back was labelled "Unknown" beside four Dutch ones.

beforeEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();
    $this->userId = $this->searchTestUser('pcw-reader');
    $this->user = User::findOrFail($this->userId);

    $this->pcwCounterparty = function (int $userId, string $type, string $slug, string $name, ?string $token): int {
        return (int) $this->conn->table('counterparties')->insertGetId([
            'user_id' => $userId,
            'type' => $type,
            'slug' => $slug,
            'display_name' => $name,
            'iban' => null,
            'merchant_name' => null,
            'metadata' => $token === null
                ? null
                : json_encode(CounterpartyDefaultName::mark([], $token), JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $this->pcwFiller = function (int $userId, int $count, string $prefix): void {
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = [
                'user_id' => $userId,
                'type' => 'merchant',
                'slug' => $prefix.'-filler-'.$i,
                'display_name' => 'Filler '.$i,
                'iban' => null,
                'merchant_name' => null,
                'metadata' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $this->conn->table('counterparties')->insert($batch);
    };
});

afterEach(function (): void {
    app()->setLocale('en');
});

/**
 * @return list<array{id: int, type: string, label: string, url: string}>
 */
function pcwCounterparties(User $user, string $q): array
{
    return array_values(array_filter(
        app(EntityNameSearch::class)->query($user, $q),
        static fn (array $hit): bool => $hit['type'] === SearchEntityKind::Counterparty->value,
    ));
}

// Re-runs each captured statement to count the rows it handed back, because a
// statement count alone cannot tell a per-reader read from a whole-table one.
/**
 * @param  callable(): void  $work
 * @return array{statements: int, rows: int}
 */
function pcwCounterpartyReads(ConnectionInterface $conn, callable $work): array
{
    /** @var list<array{sql: string, bindings: array<int, mixed>}> $seen */
    $seen = [];
    DB::listen(static function (QueryExecuted $query) use (&$seen): void {
        if (stripos($query->sql, 'counterparties') !== false) {
            $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        }
    });

    $work();

    // Counted before the replay, which runs through the same listener and
    // would otherwise report every statement twice.
    $statements = count($seen);

    $rows = 0;
    foreach ($seen as $statement) {
        $rows += count($conn->select($statement['sql'], $statement['bindings']));
    }

    return ['statements' => $statements, 'rows' => $rows];
}

it('finds the app-named counterparty by the word the reader s own screen shows', function (): void {
    $id = ($this->pcwCounterparty)($this->userId, 'unknown', 'unknown', 'Unknown', CounterpartyDefaultName::UNKNOWN);
    app()->setLocale('nl');

    $hits = pcwCounterparties($this->user, 'Onbekend');

    expect(array_column($hits, 'id'))->toBe([$id])
        ->and($hits[0]['label'])->toBe('Onbekend');
});

// The stored English keeps matching for the same reason a default category's
// does: it is what a shared screenshot, an exported CSV and a support thread
// all say. Only the label follows the reader.
it('still matches the stored English in a Dutch reader s palette, and labels it in Dutch', function (): void {
    $id = ($this->pcwCounterparty)($this->userId, 'unknown', 'unknown', 'Unknown', CounterpartyDefaultName::UNKNOWN);
    app()->setLocale('nl');

    $hits = pcwCounterparties($this->user, 'Unknown');

    expect(array_column($hits, 'id'))->toBe([$id])
        ->and($hits[0]['label'])->toBe('Onbekend');
});

it('finds the app-named government and bank-fee rows by their Dutch words too', function (): void {
    $government = ($this->pcwCounterparty)($this->userId, 'government', 'government', 'Government', CounterpartyDefaultName::GOVERNMENT);
    $fee = ($this->pcwCounterparty)($this->userId, 'bank', 'bank-fee', 'Bank fee', CounterpartyDefaultName::BANK_FEE);
    app()->setLocale('nl');

    expect(array_column(pcwCounterparties($this->user, 'Overheid'), 'id'))->toBe([$government])
        ->and(array_column(pcwCounterparties($this->user, 'Bankkosten'), 'id'))->toBe([$fee]);
});

it('never matches a row the reader named on the translation its token would have had', function (): void {
    ($this->pcwCounterparty)($this->userId, 'merchant', 'unknown-shop', 'Unknown Shop', null);
    app()->setLocale('nl');

    expect(pcwCounterparties($this->user, 'Onbekend'))->toBe([]);
});

it('keeps the English reader s own word working', function (): void {
    $id = ($this->pcwCounterparty)($this->userId, 'unknown', 'unknown', 'Unknown', CounterpartyDefaultName::UNKNOWN);

    $hits = pcwCounterparties($this->user, 'Unknown');

    expect(array_column($hits, 'id'))->toBe([$id])
        ->and($hits[0]['label'])->toBe('Unknown');
});

// display_name is ciphertext at rest once encryption is on, so the palette
// cannot match it in SQL and reads the reader's own counterparties whole,
// matching in PHP. That read is one statement over the reader's own rows and
// nothing else: resolving the app's word must not add a lookup per row, and it
// must not widen the scope past the reader.
it('reads the reader s own counterparties once and no other reader s', function (): void {
    ($this->pcwCounterparty)($this->userId, 'unknown', 'unknown', 'Unknown', CounterpartyDefaultName::UNKNOWN);
    ($this->pcwFiller)($this->userId, 600, 'pcw-mine');

    $strangerId = $this->searchTestUser('pcw-stranger');
    ($this->pcwFiller)($strangerId, 400, 'pcw-theirs');

    app()->setLocale('nl');

    $measured = pcwCounterpartyReads($this->conn, function (): void {
        app(EntityNameSearch::class)->query($this->user, 'Onbekend');
    });

    expect($measured)->toBe(['statements' => 1, 'rows' => 601]);
});
