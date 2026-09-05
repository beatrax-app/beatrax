<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Actions\TaxCategoryStore;
use Modules\Tax\Internal\Support\TaxCorpusWording;
use Modules\Tax\Public\Services\TaxCategoryWriter;

// The corpus seeds a deduction category in the jurisdiction's language and the
// seed is insert-only, so an English reader filing in the Netherlands read
// "Zorgkosten", "Giften" and "Eigen woning" off the screen with nothing behind
// them to fall back to. `corpus_key` was already on the row and already read for
// idempotency; it was never read for display.

function drrlUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
    ]);
}

/** @return array<string, stdClass> corpus key => the row as a screen would read it */
function drrlByKey(int $userId): array
{
    $byKey = [];
    foreach (app(TaxCategoryStore::class)->listForUser($userId, true) as $row) {
        $byKey[is_string($row->corpus_key) ? $row->corpus_key : 'own:'.$row->name] = $row;
    }

    return $byKey;
}

beforeEach(function (): void {
    TaxCorpusWording::forget();
    $this->user = drrlUser('corpus-wording-'.bin2hex(random_bytes(4)));
    app(TaxCategoryStore::class)->seedFromCorpus($this->user, 'nl');
});

afterEach(function (): void {
    app()->setLocale('en');
});

it('names a Dutch corpus category in English for an English reader', function (): void {
    app()->setLocale('en');

    $rows = drrlByKey($this->user->id);

    expect($rows['nl_zorgkosten']->name)->toBe('Healthcare costs')
        ->and($rows['nl_giften']->name)->toBe('Donations')
        ->and($rows['nl_eigen_woning']->name)->toBe('Own home');
});

it('keeps the corpus wording for a Dutch reader', function (): void {
    app()->setLocale('nl');

    $rows = drrlByKey($this->user->id);

    expect($rows['nl_zorgkosten']->name)->toBe('Zorgkosten')
        ->and($rows['nl_giften']->name)->toBe('Giften');
});

// The picker prints the hint under the name, and the badge prints short_name,
// so a translated name beside a Dutch hint would be half a fix.
it('carries the badge label and the picker hint into the same language', function (): void {
    app()->setLocale('en');
    $english = drrlByKey($this->user->id);

    app()->setLocale('nl');
    $dutch = drrlByKey($this->user->id);

    expect($english['nl_zorgkosten']->short_name)->toBe('Healthcare')
        ->and($english['nl_zorgkosten']->hint)->not->toBe($dutch['nl_zorgkosten']->hint)
        ->and($dutch['nl_zorgkosten']->hint)->toBe('Ziektekosten boven drempel (eigen risico, hulpmiddelen, etc.)');
});

// A rename is the user's own words. The corpus key survives it — dropping the
// key would make the next re-seed insert the row again and undo the rename —
// so the flag is what tells the two apart.
it('leaves a renamed category verbatim in every language', function (): void {
    $rows = drrlByKey($this->user->id);
    $id = (int) $rows['nl_zorgkosten']->id;

    app(TaxCategoryWriter::class)->rename($this->user->id, $id, 'Dokterskosten');

    app()->setLocale('en');
    expect(drrlByKey($this->user->id)['nl_zorgkosten']->name)->toBe('Dokterskosten');

    app()->setLocale('nl');
    expect(drrlByKey($this->user->id)['nl_zorgkosten']->name)->toBe('Dokterskosten');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('tax_deduction_categories')->where('id', $id)->value('name_is_default'))
        ->not->toBe(1);
});

it('leaves a category the reader added alone, in every language', function (): void {
    app(TaxCategoryWriter::class)->add($this->user->id, 'Kilometervergoeding', 'Km', 'Zakelijke kilometers');

    foreach (['en', 'nl'] as $locale) {
        app()->setLocale($locale);
        expect(drrlByKey($this->user->id)['own:Kilometervergoeding']->name)->toBe('Kilometervergoeding');
    }
});

// The seeded row is the corpus's own wording until somebody writes over it, and
// the flag has to say so on the row itself: `corpus_key` alone cannot, because
// it survives the rename that makes the wording the user's.
it('marks a seeded row as the corpus wording and an added one as not', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    app(TaxCategoryWriter::class)->add($this->user->id, 'Kilometervergoeding');

    $flags = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $this->user->id)
        ->pluck('name_is_default', 'name')
        ->all();

    expect((bool) $flags['Zorgkosten'])->toBeTrue()
        ->and((bool) $flags['Kilometervergoeding'])->toBeFalse();
});
