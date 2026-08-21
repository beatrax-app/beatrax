<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// A frozen copy of what DefaultCategoryTreeSeeder seeded from. The seeder's
// array may be reworded later; what this backfill needs is the wording the
// rows already out there came from, so it carries its own.
return new class extends ModuleMigration
{
    private const DEFAULT_NAMES = [
        'income' => 'Income',
        'income-salary' => 'Salary',
        'income-refunds' => 'Refunds',
        'income-other' => 'Other income',
        'housing' => 'Housing',
        'housing-rent' => 'Rent / Mortgage',
        'housing-utilities' => 'Utilities',
        'housing-internet' => 'Internet & Phone',
        'groceries' => 'Groceries',
        'transport' => 'Transport',
        'transport-public' => 'Public transport',
        'transport-fuel' => 'Fuel',
        'transport-car' => 'Car maintenance',
        'insurance' => 'Insurance',
        'insurance-health' => 'Health',
        'insurance-liability' => 'Liability',
        'insurance-other' => 'Other',
        'subscriptions' => 'Subscriptions',
        'subscriptions-streaming' => 'Streaming',
        'subscriptions-music' => 'Music',
        'subscriptions-cloud' => 'Cloud / Software',
        'subscriptions-memberships' => 'Memberships',
        'eating-out' => 'Eating out',
        'cash-withdrawal' => 'Cash withdrawal',
        'healthcare' => 'Healthcare',
        'personal-care' => 'Personal care',
        'donations' => 'Donations',
        'transfers-internal' => 'Transfers (internal)',
        'fees' => 'Fees & charges',
    ];

    public function up(): void
    {
        if (! $this->schema()->hasTable('categories') || $this->schema()->hasColumn('categories', 'name_is_default')) {
            return;
        }

        // Default false, so a row of unknown provenance — an imported tree, a
        // row a future writer creates — keeps its name verbatim, which is what
        // every row does today.
        $this->schema()->table('categories', static function (Blueprint $table): void {
            $table->boolean('name_is_default')->default(false);
        });

        $connection = $this->db()->connection($this->getConnection());

        // No rename can have reached a global row: the seeder writes the name
        // once at creation, the migration importer scopes every write to the
        // user's own id, and no rename UI exists. So a null-user row still
        // holds the signup locale's wording of its slug, and nothing is lost.
        $connection->transaction(function () use ($connection): void {
            foreach (self::DEFAULT_NAMES as $slug => $name) {
                $connection->table('categories')
                    ->whereNull('user_id')
                    ->where('slug', $slug)
                    ->update(['name' => $name, 'name_is_default' => true]);
            }
        });
    }

    public function down(): void
    {
        if (! $this->schema()->hasColumn('categories', 'name_is_default')) {
            return;
        }

        // The English left behind is deliberate: it is the wording the tree
        // was always seeded from on an English install, and re-guessing a
        // locale to translate it back into is how this bug started.
        $this->schema()->table('categories', static function (Blueprint $table): void {
            $table->dropColumn('name_is_default');
        });
    }
};
