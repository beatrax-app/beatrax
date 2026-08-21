<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\LocaleNegotiator;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\TransactionListQuery;

function cnUser(string $username, ?string $locale): User
{
    /** @var User $user */
    $user = User::create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'locale' => $locale,
    ]);

    return $user;
}

// The same two steps SetLocale performs per request, so a test reads the app
// through the locale its user would really have had.
function cnReadAs(User $user): void
{
    app()->setLocale(app(LocaleNegotiator::class)->resolve($user->locale, null, null));
}

function cnPickerLabel(User $user, string $slug): string
{
    $id = (int) Category::withoutGlobalScopes()->whereNull('user_id')->where('slug', $slug)->value('id');

    foreach (app(CategoryOptionsQuery::class)->for($user) as $option) {
        if ($option->id === $id) {
            return $option->path;
        }
    }

    return '';
}

function cnStoredName(string $slug): string
{
    $name = Category::withoutGlobalScopes()->whereNull('user_id')->where('slug', $slug)->value('name');

    return is_string($name) ? $name : '';
}

function cnTransactionIn(DatabaseManager $db, User $user, int $categoryId): void
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'cn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00CNFT00000001', 'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/cn.csv',
        'sha256' => str_pad('cn', 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $postedAt = now()->toDateString();
    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id, 'account_id' => $accountId, 'import_run_id' => $runId, 'category_id' => $categoryId,
        'fingerprint' => str_pad('cn', 64, 'c', STR_PAD_LEFT), 'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00', 'value_date' => $postedAt,
        'amount_minor' => -4200, 'currency' => 'EUR', 'settled_amount_minor' => -4200, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ah', 'counterparty_name' => 'AH', 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    app()->setLocale('en');
    app(DefaultCategoryTreeSeeder::class)->run();
});

it('names a default category in the reader language, and again in the next one', function (): void {
    $user = cnUser('cn-reader', 'nl');
    cnReadAs($user);

    expect(cnPickerLabel($user, 'groceries'))->toBe('Boodschappen');

    $user->locale = 'de';
    cnReadAs($user);

    expect(cnPickerLabel($user, 'groceries'))->toBe('Lebensmittel')
        ->and(cnStoredName('groceries'))->toBe('Groceries');
});

it('keeps a rename verbatim in every language', function (): void {
    $user = cnUser('cn-renamer', 'nl');

    Category::withoutGlobalScopes()->whereNull('user_id')->where('slug', 'groceries')
        ->update(['name' => 'Supermarkt', 'name_is_default' => false]);

    cnReadAs($user);
    expect(cnPickerLabel($user, 'groceries'))->toBe('Supermarkt');

    $user->locale = 'de';
    cnReadAs($user);
    expect(cnPickerLabel($user, 'groceries'))->toBe('Supermarkt');
});

it('gives two household members on one tree the language each of them chose', function (): void {
    $dutch = cnUser('cn-member-nl', 'nl');
    $german = cnUser('cn-member-de', 'de');

    cnReadAs($dutch);
    $dutchLabel = cnPickerLabel($dutch, 'eating-out');

    cnReadAs($german);
    $germanLabel = cnPickerLabel($german, 'eating-out');

    // Reading in one language must not have rewritten the shared row out from
    // under the other member — the reason the seeder never rewrote names.
    expect($dutchLabel)->toBe('Uit eten')
        ->and($germanLabel)->toBe('Auswärts essen')
        ->and(cnStoredName('eating-out'))->toBe('Eating out');
});

it('reaches a raw query-builder join, not only the Eloquent read', function (): void {
    $user = cnUser('cn-joined', 'nl');
    $groceries = (int) Category::withoutGlobalScopes()->whereNull('user_id')->where('slug', 'groceries')->value('id');
    cnTransactionIn($this->db, $user, $groceries);

    cnReadAs($user);
    $page = app(TransactionListQuery::class)->recent($user);

    expect($page->rows)->toHaveCount(1)
        ->and($page->rows[0]->categoryName)->toBe('Boodschappen');
});

it('reaches the budget category map, which the budgets page and onboarding both read', function (): void {
    $user = cnUser('cn-budgets', 'nl');
    cnReadAs($user);

    expect(app(BudgetProgressQuery::class)->expenseCategories($user))->toContain('Boodschappen');
});

it('keeps the stored name when a default row has no translation for its slug', function (): void {
    $user = cnUser('cn-untranslated', 'nl');
    Category::withoutGlobalScopes()->create([
        'user_id' => null, 'parent_id' => null, 'name' => 'Pet supplies',
        'slug' => 'pet-supplies', 'kind' => 'expense', 'display_order' => 900, 'name_is_default' => true,
    ]);

    cnReadAs($user);

    expect(cnPickerLabel($user, 'pet-supplies'))->toBe('Pet supplies');
});
